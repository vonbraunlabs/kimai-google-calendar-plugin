# Kimai Google Calendar Plugin - Guidelines for Claude Code

> **Session start:** read `docs/STATUS.md` first — where the last session stopped, what to do
> next, and known technical debts. It is faster than rebuilding the context from the git history.

Workflow, gates and artifacts replicated from the Karajan project (`vonbraunlabs/karajan`),
adapted to a PHP/Symfony plugin. Where Karajan does not apply (its own visual identity, .NET
backend/Angular frontend), the adaptation is recorded in an ADR (`docs/adrs/`).

## 1. Context and Architecture
Kimai 2 plugin that imports **Google Calendar events** and **completed Google Tasks** as timesheet
records. Each user connects their own Google account (OAuth 2, read-only), picks a period and
**reviews** the items in a table before anything is registered.
*   **Platform:** Symfony bundle loaded by Kimai 2.x from `var/plugins/GoogleCalendarBundle` (the directory **must** have this name). PHP 8.3, namespace `KimaiPlugin\GoogleCalendarBundle\`.
*   **Kimai integration:** entities, repositories, `TimesheetService` (lockdown, overlap and permission validation), `SystemConfiguration`, menu and Tabler theme. The plugin **never** saves a timesheet bypassing `TimesheetService`.
*   **Google integration:** own REST client (`Service/GoogleApiClient.php`) on top of Symfony's `HttpClient` — OAuth2, Calendar v3 and Tasks v1. No Google SDK.
*   **Database:** Kimai's (MySQL/MariaDB). Own tables `kimai2_google_calendar_accounts` and `kimai2_google_calendar_links`, created by migration through `bin/console kimai:bundle:google-calendar:install`.
*   **Architecture decisions:** `docs/adrs/` — read the index in `docs/adrs/README.md` before changing the import flow, OAuth, project/activity identification, UI or test strategy.

## 2. Daily Workflow (GitHub CLI)
When starting work on a new feature, follow this cycle strictly:
*   List pending tasks with `gh issue list`.
*   Read the requirements, DoR and DoD of the chosen task with `gh issue view <ID>`.
*   Implement incrementally, with small, contextual semantic commits (`feat:`, `fix:`, `test:`, `refactor:`, `docs:`, `ci:`, `build:`, `chore:`) — each commit buildable and with its own tests, never the whole issue in one block.
*   Finish by opening the Pull Request with `gh pr create`, describing what was done and marking the issue for automatic closing (`Closes #<ID>`).

## 3. Versioning and Git Flow
The project uses continuous integration on the development branch. `master` is production only (it is what administrators clone into `var/plugins/`).
*   The base branch for any new development is always `develop`.
*   Before coding, create a branch from develop: `git checkout -b feature/issue-<ID>-short-description origin/develop`.
*   When opening the Pull Request, the base **must** be `develop`.
*   `develop` is protected: 1 human approval plus the CI status checks. No PR merges itself, even with green checks — never attempt or assume an automatic merge.
*   The `version` field in `composer.json` is the published plugin version; it only changes when `develop` is integrated into `master`, not in feature PRs.
*   `composer.lock` is versioned: it pins the `kimai/kimai` dev dependency to the same version as `docker/Dockerfile`, so unit tests and the installation check run against the same Kimai. When bumping Kimai, update both together.

## 4. Tests and Coverage (Minimum 80%)
No code may be submitted in a Pull Request without reaching at least 80% line coverage in unit tests (PHPUnit). The strategy is in ADR-0005: Kimai is a dev dependency, with no database or kernel in the tests.
*   **Run (no local PHP, via Docker):** `docker compose run --rm gcal-test sh -c 'composer install -n && composer test-coverage && php .github/scripts/coverage-gate.php coverage/clover.xml 80'`.
*   **Run (local PHP 8.3 + pcov):** `composer install && composer test-coverage && php .github/scripts/coverage-gate.php coverage/clover.xml 80`.
*   **Real installation in Kimai:** `docker compose up -d --build --wait` and `docker compose exec gcal-kimai bin/console kimai:bundle:google-calendar:install -n` — covers migrations and templates, which PHPUnit does not exercise.
*   **Autonomous fixing:** if validation fails for insufficient coverage, write the missing tests before moving on to the PR. Never lower the threshold or widen the exclusions in `phpunit.xml.dist` to pass the gate.
*   **CI:** `.github/workflows/ci.yml` runs the same commands on every PR to `develop`/`master` (job `tests`), installs the plugin in a real Kimai of the version pinned in `docker/Dockerfile` (job `kimai-install`) and comments the coverage summary on the PR. The gate uses the same `.github/scripts/coverage-gate.php` script as the local environment.

## 5. Project Skills and Agents
Use these instead of rebuilding the flow by hand:
*   **Skill `iniciar-issue`:** runs the cycle of section 2 (reads the GitHub issue, checks DoR/DoD, creates the branch from `develop`).
*   **Skill `regras-de-importacao`:** business rules of the import flow (manual review, idempotency, project/activity identification, event filters, OAuth and tokens), distilled from ADRs 0001–0003/0006 and issue #1. Load before touching `Service/`, `Entity/`, `Controller/` or the migrations.
*   **Skill `pre-pr-check`:** validates tests + the 80% coverage gate + the plugin installation in a real Kimai via `docker compose` before any `gh pr create`.
*   **Skill `ui-kimai`:** applies ADR-0004 (screens in Kimai's theme, no visual identity of their own) and the en/pt_BR/pt translation rules. Load before writing or reviewing any Twig template, form or translation file.
*   **Agent `gcal-domain-reviewer`:** reviews domain-rule and OAuth-security fidelity (not a substitute for code-quality review) — run after implementing code that touches the import, timesheet registration, Google↔Kimai links, project/activity identification, OAuth or tokens.

## 6. Language, Interface and Translations
*   Code, identifiers, comments, commit messages, and every AI-facing file (this file, skills, agents, `docs/STATUS.md`) are in **English**. ADRs are written in Portuguese (team convention, inherited from Karajan).
*   The UI is **internationalized**: it follows the Kimai user's language preference, and Kimai's translator falls back to English for any locale the plugin does not ship. No visible text is hardcoded in PHP or Twig — everything goes through a `gcal.*` translation key present in `en`, `pt_BR` and `pt`.
*   Screens follow Kimai's theme (ADR-0004): they extend `base.html.twig`, use only Tabler components and have no colors, fonts or stylesheets of their own. See the `ui-kimai` skill for the checklist.

## 7. Secrets
`google-api-secret.json`, `client_secret*.json` and any real Client Secret/token never go into a commit, log, flash message, PR description or issue comment. Test credentials go through environment variables (`GOOGLE_CALENDAR_CLIENT_ID`/`GOOGLE_CALENDAR_CLIENT_SECRET`) or the system configuration of the local Kimai.
