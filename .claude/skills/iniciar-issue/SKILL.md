---
name: iniciar-issue
description: Start work on a kimai-google-calendar-plugin backlog issue — reads the GitHub issue (DoR/DoD), creates the feature branch from develop, and sets up the incremental-commit + PR loop described in CLAUDE.md. Use whenever the user says "vamos começar a issue X" / "implementa a issue #X" / "próxima tarefa do backlog".
---

Operationalizes CLAUDE.md §2 ("Fluxo de Trabalho Diário") and §3 ("Versionamento e Git Flow") so
every issue is started the same way, with the right base branch and the right DoR/DoD context
loaded before a single line of code is written.

## Step 0 — Load the session context

Read `docs/STATUS.md` (where the last session stopped, open technical debts) and the ADR index in
`docs/adrs/README.md`. An issue that contradicts an accepted ADR needs a new ADR that supersedes
it, not a silent code change — say so to the user before starting.

## Step 1 — Pick the issue

If the user named an issue number, use it. Otherwise:

```
gh issue list --repo vonbraunlabs/kimai-google-calendar-plugin --state open
```

Don't start an issue whose DoR depends on an issue that isn't closed yet; say so and offer the
dependency issue instead.

## Step 2 — Read the issue in full

```
gh issue view <ID> --repo vonbraunlabs/kimai-google-calendar-plugin --comments
```

Confirm the **DoR (Definition of Ready)** items are actually satisfied — if not, stop and tell the
user what's missing rather than starting work that can't reach Done. DoR items typical for this
repo that you cannot verify yourself (a Google Cloud OAuth client with Calendar/Tasks APIs
enabled, a Kimai instance for manual validation) — ask the user instead of assuming. Keep the
**DoD (Definition of Done)** and **Resultado Esperado** visible for the rest of the session; they
are the acceptance criteria the PR will be checked against, not a suggestion.

Load the `regras-de-importacao` skill before designing anything that touches the import flow,
timesheet registration, the Google↔Kimai links, project/activity identification, OAuth or token
storage. Load `ui-kimai` before touching templates, forms or translations.

## Step 3 — Create the branch from develop

```
git fetch origin develop
git checkout -b feature/issue-<ID>-<breve-descricao-kebab> origin/develop
```

Bugs use `fix/issue-<ID>-...`. If the working tree has uncommitted changes, stop and ask — don't
stash or carry someone's in-progress work onto the new branch. If `develop` doesn't exist on the
remote, say so explicitly and ask whether to branch from the current default branch instead — do
not silently substitute `master`, since CLAUDE.md reserves that branch for production.

## Step 4 — Implement incrementally with semantic commits

Work in small, buildable increments; commit with semantic messages (`feat:`, `fix:`, `test:`,
`refactor:`, `docs:`, `ci:`) as each piece lands, per CLAUDE.md §2. Don't batch the whole issue
into one commit — the point of "incremental" is that each commit is independently reviewable.

Write tests alongside the code, not after — CLAUDE.md §4 requires 80% line coverage before the PR
can land, and back-filling tests at the end tends to under-cover the edge cases the DoD actually
cares about (period boundaries and timezones, whole-word matching, rows missing from the POST,
already-registered items, expired/revoked tokens). Follow ADR-0005: mock Kimai classes with
`createMock()`, but use the **real** `final` Kimai classes through `Tests/KimaiMocks.php` /
`Tests/Fixtures.php` rather than hand-written stubs.

If the change introduces a decision that is expensive to reverse (new Google scope, new table or
column, different identification order, automatic registration of any kind), write the ADR in
the same PR, following `docs/adrs/README.md`.

A schema change means a **new** migration in `Migrations/` — never edit a migration that has
already shipped on `master`, since installations that already ran it will never run it again.

## Step 5 — Before opening the PR

1. If the diff touches domain code (see Step 2), run the `gcal-domain-reviewer` agent on it and
   address its findings.
2. Run the `pre-pr-check` skill. It gates on the same coverage threshold and Kimai installation
   check CI runs, and will tell you what's missing rather than let a doomed PR reach CI.
3. Update `docs/STATUS.md` (issue status, what's next, new technical debts).

## Step 6 — Open the PR

```
gh pr create --repo vonbraunlabs/kimai-google-calendar-plugin --base develop \
  --title "..." --body "... Closes #<ID>"
```

`Closes #<ID>` (or `Fixes #<ID>`) auto-closes the issue on merge — per CLAUDE.md §2, every PR
should close the issue it implements. Confirm the PR's base is `develop`, never `master`
(CLAUDE.md §3). The PR body lists the DoD items one by one with how each was met, plus the
`pre-pr-check` report.
