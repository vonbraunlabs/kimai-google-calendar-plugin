---
name: pre-pr-check
description: Run this repo's PHPUnit suite with the 80% line-coverage gate and validate that the plugin installs and renders inside a real Kimai via docker compose, before opening or updating a Pull Request. Use whenever a PR is about to be created for PHP, Twig, translation, migration, composer or docker/compose changes in this repo.
---

Catches a broken build, failing tests, a coverage regression, or a plugin that doesn't load in
Kimai before they reach CI or a human reviewer — CLAUDE.md requires 80% line coverage on every
PR, and opening a PR you already know will fail that gate wastes a review cycle.

`.github/workflows/ci.yml` runs the same checks on every PR (jobs `tests` and `kimai-install`)
and posts the coverage summary as a PR comment — this skill exists to catch the same failures
*before* pushing, for faster feedback than waiting on CI.

## When to run

Right before `gh pr create` (or pushing new commits to an open PR) for this repo. Run it even if
only `composer.json`, `docker/Dockerfile`, `docker-compose.yml` or a translation file changed —
those can break the plugin with zero PHP changes (a Kimai bump in `docker/Dockerfile` can break
templates; a missing translation key renders as the raw key).

## Retry limit — do not loop forever

Keep a single attempt counter across Steps 2 and 3 combined for this PR-prep pass:

- **Cap at 5 fix attempts total.** Each time you change something in response to a failing test,
  a coverage shortfall, or a failing Kimai installation and re-run the check, that's one attempt.
- Stop earlier than 5 if the blocker isn't something a code change can fix (Docker Hub or
  Packagist unreachable, a missing credential, a flaky infra issue) — don't burn the full budget
  on something no retry will resolve.
- When the cap is reached (or the blocker is identified as unfixable) without a green result, do
  **not** open the PR. Say explicitly what failed and why, and ask the user how to proceed.

## Step 1 — Identify what changed

```
git fetch origin develop
git diff --name-only $(git merge-base HEAD origin/develop)...HEAD
```

(include uncommitted changes with `git status --short` if the PR isn't fully committed yet).
Map touched paths to checks:

- `*.php` outside `Migrations/`, `Tests/**`, `phpunit.xml.dist`, `composer.json` → Step 2
- `Migrations/**`, `Entity/**`, `Resources/views/**`, `Resources/config/**`,
  `Resources/translations/**`, `docker/**`, `docker-compose.yml`, `.dockerignore`,
  `composer.json` → Step 3 (migrations and templates are excluded from / not exercised by the
  coverage metric — ADR-0005 — so the real installation is their only check)
- `Resources/translations/**` → also Step 2b

If only `docs/**`, `*.md` or `.claude/**` changed, Steps 2/3 can be skipped — but say so
explicitly instead of silently doing nothing.

## Step 2 — Run the test suite with the coverage gate (CLAUDE.md §4)

There is no PHP on the developer machine by default; use the versioned test container
(`docker/test/Dockerfile`, service `gcal-test`, profile `test`):

```
docker compose run --rm gcal-test sh -c \
  'composer install -n --no-progress && composer test-coverage && php .github/scripts/coverage-gate.php coverage/clover.xml 80'
```

If `php -v` reports 8.3 with pcov locally, the same three commands can run without Docker.

- A gate failure exits non-zero even if every test passes — treat it exactly like a failing test:
  write the missing unit tests before proceeding. Never lower the threshold, add
  `@codeCoverageIgnore`, or widen the `<exclude>` list in `phpunit.xml.dist` to pass.
- `failOnRisky`/`failOnWarning` are on: a deprecation or risky test fails the run on purpose.
- `composer.lock` is versioned and pins `kimai/kimai` to the same version as `ARG KIMAI_VERSION`
  in `docker/Dockerfile`; CI fails when they differ. When bumping Kimai (e.g. a dependabot PR),
  update both in the same PR: `composer update kimai/kimai --with-all-dependencies` inside
  `gcal-test`, plus the Dockerfile `ARG`, then run Steps 2 and 3.

### Step 2b — Translation parity

Every key must exist in `en`, `pt_BR` and `pt` (`pt` mirrors `pt_BR`), for both `messages` and
`flashmessages`:

```
for d in messages flashmessages; do
  diff <(grep -oE '^ +[a-z_]+:' Resources/translations/$d.en.yaml | sort) \
       <(grep -oE '^ +[a-z_]+:' Resources/translations/$d.pt_BR.yaml | sort) \
  && diff -q Resources/translations/$d.pt_BR.yaml Resources/translations/$d.pt.yaml
done
```

Any output is a failure — fix it (it counts against the retry limit).

## Step 3 — Validate the plugin installs in a real Kimai

This is the step that catches what the unit tests can't: a migration with broken SQL, an entity
mapping out of sync with the migration, a template that doesn't compile against the Kimai version
pinned in `docker/Dockerfile`, a service that fails to autowire.

1. If Docker isn't available in this execution environment (`docker info` fails), **do not skip
   silently**. Say explicitly, in the PR description and to the user, that the installation could
   not be verified here and must be verified locally before merging (the CI job `kimai-install`
   also covers it). Continue with Step 2 and don't count this against the retry limit.
2. Use an isolated compose project name so this never collides with the user's local stack
   (`kimai-gcal`) — same name CI uses. Run it with `run_in_background`: the first start runs every
   Kimai migration and took ~13 minutes on the developer machine (2026-09-30). The healthcheck's
   `start_period` in `docker-compose.yml` exists so `--wait` survives that; don't remove it.
   `KIMAI_ADMIN_PASSWORD=verify-password-123 docker compose -p gcal-verify up -d --build --wait --wait-timeout 1200`
3. Install and check the plugin, exactly as CI does (`working_dir` in the compose file makes the
   relative `bin/console` work — the image's own WORKDIR is `/var/www/html`):
   ```
   docker compose -p gcal-verify exec -T gcal-kimai bin/console kimai:bundle:google-calendar:install -n
   docker compose -p gcal-verify exec -T gcal-kimai bin/console kimai:plugins | grep GoogleCalendarBundle
   docker compose -p gcal-verify exec -T gcal-kimai bin/console lint:twig var/plugins/GoogleCalendarBundle/Resources/views
   docker compose -p gcal-verify exec -T gcal-kimai bin/console doctrine:schema:update --dump-sql > <scratchpad>/schema.sql
   grep -i google_calendar <scratchpad>/schema.sql
   ```
   Check every exit code. The `--dump-sql` command must exit 0 and the final `grep` must find
   **nothing**: any `google_calendar` SQL means the entities and the migrations disagree. Never
   pipe `exec` straight into `grep` for this check — if `exec` fails, the empty output looks like
   a pass.
4. `curl -fsS -o /dev/null http://localhost:8001/en/login` must succeed.
5. If anything fails, pull logs before giving up:
   `docker compose -p gcal-verify logs --tail=200 gcal-kimai`. Fix the root cause and restart
   from step 2 — this counts against the retry limit.
6. Always clean up, pass or fail: `docker compose -p gcal-verify down -v`.

The OAuth round trip with Google (connect, load a period, register) needs a real Client ID/Secret
and an HTTPS or `localhost` redirect URI — it cannot be automated here. When the PR changes that
flow, say in the PR that manual validation against Google is still pending (or was done by the
user), never that it passed.

## Step 4 — Report before opening the PR

When Steps 2 and 3 are green (or explicitly and legitimately skipped per Step 1), state plainly in
the PR description:

- Test count and the resulting line coverage percentage (from the gate output).
- Whether the Kimai installation was verified, against which Kimai version (`docker/Dockerfile`),
  or exactly why it wasn't.
- Whether the Google OAuth flow was validated manually, and by whom.
- Which Issue(s) this PR closes (`Closes #<n>`), per CLAUDE.md §2.

Never claim a check passed without having actually run it in this session.

## When you can't get it green — escalate instead of opening the PR

If the retry cap is hit, or the blocker is identified as something you cannot resolve yourself:

1. **Do not open the PR.**
2. Comment on the corresponding GitHub Issue (`gh issue comment <n>`) with:
   - What was being implemented.
   - Which check is failing (tests, coverage, translations, Kimai installation) and the concrete
     error output — with any token, Client Secret or e-mail address redacted.
   - What was already tried across the attempts, and why it didn't resolve it.
3. Report the same summary directly to the user and ask how to proceed.
