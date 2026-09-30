# Project Status

Living document — updated at the end of every work session, to pick the context back up without
rebuilding it from scratch. The source of truth for *requirements* is still the
[GitHub issues](https://github.com/vonbraunlabs/kimai-google-calendar-plugin/issues); this file is
about *where we stopped and what to do next*.

## Last update: 2026-09-30 (session 2)

## Current state

| Issue | Status |
|---|---|
| #1 Import Google Calendar events and Google Tasks as Kimai timesheets | 🚧 Implemented on `feature/issue-1-google-calendar-import`, PR to `develop` pending review |

- Tests: 100 tests, **99.83%** line coverage, 80% gate passing (2026-09-30, via
  `docker compose run --rm gcal-test …`, CLAUDE.md §4).
- ADRs 0001–0006 accepted (`docs/adrs/README.md`).
- CI (`.github/workflows/ci.yml`): jobs `tests` (Kimai version match + PHPUnit + gate),
  `kimai-install` (real Kimai 2.67.0, pinned in `docker/Dockerfile`) and `comment`.
- `develop` is protected: 1 approval + CI status checks.

## Session 2 (2026-09-30) — development environment replicated from Karajan

- Added `CLAUDE.md`, skills `iniciar-issue`, `pre-pr-check`, `regras-de-importacao` (the
  counterpart of Karajan's `motor-de-custos`, built from ADRs 0001–0003/0006) and `ui-kimai`
  (replaces `identidade-visual`, per ADR-0004), agent `gcal-domain-reviewer` and this file. All
  AI-facing files are in English (Diego's decision); ADRs stay in Portuguese.
- The test toolchain, previously an unversioned local image (`gcal-php-test`), is now
  `docker/test/Dockerfile` + the `gcal-test` compose service (profile `test`).
- Validating `pre-pr-check` end to end exposed defects in the `kimai-install` job, now fixed in
  `docker-compose.yml`/`ci.yml`: the first start (MySQL init + all Kimai migrations, ~20 min on the
  developer machine) outlived the healthchecks and `up --wait` gave up → `start_period` on both
  services; the image's WORKDIR is `/var/www/html`, so `exec … bin/console` failed →
  `working_dir: /opt/kimai`; `! exec … | grep` passed when `exec` failed → output written to a
  file first. `lint:twig` added.
- The single uncommitted block of issue #1 was split into contextual commits, each passing its
  own tests in an isolated worktree.
- Decisions from Diego applied: `composer.lock` is versioned (pins Kimai 2.67.0, same as Docker,
  with a CI guard); mapping-rule keywords match as whole words only (ADR-0006); all remaining
  hardcoded UI strings are translated (Kimai's translator falls back to English).
- Technical debts from session 1 resolved: stale `GoogleCalendarLink` docblock, unused
  `source_updated` column (removed from the not-yet-shipped initial migration), Kimai version
  mismatch between unit tests and the installation check.

## Next steps

1. Review and merge the issue #1 PR into `develop` (1 human approval).
2. Manual validation of the OAuth flow with a real Google client (issue #1 DoR) — not automatable.
3. Integrate `develop` into `master` and tag the first release (`version` in `composer.json` is
   already `1.0.0`).

## Known technical debts

- A local database that ran the initial migration before 2026-09-30 still has the
  `source_updated` column; it is nullable and unmapped, so harmless. Drop it by hand or recreate
  the dev database (`docker compose down -v`).
- The first `docker compose up` from an empty volume takes ~20 minutes on the developer machine.
  If the CI runner is also slow, `--wait-timeout 1200` may need raising.
