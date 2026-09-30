---
name: regras-de-importacao
description: Domain and security rules of the Google Calendar/Tasks → Kimai import flow — manual review before registering, idempotent Google↔timesheet links, the project/activity identification order, which events are listed, how tasks become time, and the OAuth/token rules — distilled from ADRs 0001-0003/0006 and issue #1. Load before modeling data or changing logic in Service/, Entity/, Controller/, Repository/ or Migrations/ — these rules are easy to break with code that still passes its tests.
---

The issue DoD (`gh issue view 1`) says *what* to build; this skill captures the *why* and the
constraints behind it, from `docs/adrs/0001`–`0003` and `0006` — decisions Diego Duarte made after testing
the first version against his real calendar. Read the ADR directly if a rule below feels
incomplete; this is a summary, not a replacement. Changing any rule here means a **new ADR** that
supersedes the old one, not just a code change.

## 1. Nada é registrado sem revisão do usuário (ADR-0001)

The first version synced by itself (button or cron) into a default project/activity, and updated
or deleted records when the Google event changed. It was rejected after real use: "importar ou
não" is too little — project, activity and description are frequently different, and some events
must never become time. A wrong record created behind the user's back costs more to find and fix
than to confirm in a table.

**Model it as:** load period → review table → user checks rows → POST → register only those rows.
Never add a cron, console command, event subscriber, message handler or "auto-import" option that
creates timesheets. Don't reintroduce a default project/activity on the account, or "create
activity from title" — they were removed on purpose (see the diff of
`Migrations/Version20260928000000.php`).

**The server never trusts the pre-selection.** `CalendarImportService::applyInput` deselects every
item whose key is missing from the POSTed `rows` — this was a real bug (unsubmitted, pre-selected
rows got registered), now covered by a test. Any new submission path must keep "not in the POST →
not registered".

**Pre-selected only if** project **and** activity were identified **and** the item already ended
(`end <= now`). Running and future items are never pre-checked.

## 2. Depois de registrado, o Google não muda mais nada (ADR-0001)

A registered item is read-only in the table and can't be registered twice. Later changes in
Google (new time, new title, cancellation) never update or delete the Kimai record — that would
overwrite what the user edited during review. The sync version's `source_updated` column was
removed on purpose; don't add a Google revision/`updated` field back to build "update from Google".

## 3. Idempotência pelo vínculo Google ↔ timesheet

`GoogleCalendarLink` (unique on `account_id, source_type, source_id`) records which event/task
produced which timesheet. The item key is `type:sourceId` — `sourceId` for recurring events is the
**instance** id (`singleEvents=true`), so each occurrence is its own item.

- Link with a timesheet → item is registered (read-only).
- Timesheet deleted in Kimai → FK `ON DELETE SET NULL` leaves the link with `timesheet = null` →
  the item shows up as **pending again**, and registering it **reuses** the existing link
  (a new row would violate the unique constraint). This is ADR-0001 behavior.
- Never create a timesheet without creating/updating its link in the same flow, and never match
  items by title/time instead of source id.

## 4. Ordem de identificação de projeto e atividade (ADR-0003)

Project first, then activity (among the chosen project's activities plus global ones, if the
project allows global activities). Same order for both — the first step that finds something wins:

1. **number** (Kimai code) in the title;
2. **name** in the title;
3. **name** in the description (HTML converted to text);
4. first **mapping rule** whose keyword is in the title.

- Number, name **and mapping-rule keyword** all match as a **whole word**, case-insensitive,
  through the single helper `TextMatcher::containsWord()` (ADR-0006) — a word is delimited by
  anything that is not a letter or digit: "QA" matches "[GPV0374][QA] Estabelecimento de fluxo"
  and "Testes de QA", never "QUALIDADE". Substring matching (`str_contains`, `mb_stripos`,
  `LIKE %…%`) anywhere in identification is a violation, not a shortcut.
- Among numbers/names the **longest wins** ("ACME Website" beats "ACME"); among rules the
  **first in configured order** wins (the user controls priority by line order).
- Changing the matching criterion changes perceived behavior: new ADR + update `TextMatcherTest`
  (and `TargetResolverTest`/`MappingRulesTest` if the order changes).
- Rules come **last** by Diego's explicit request: numbers and names are Kimai's own data and more
  reliable. Don't move rules up "because they're more specific".
- Candidates are limited to what the user may book on: `ProjectFormTypeQuery`/`ActivityFormTypeQuery`
  with `setUser()` (Kimai team permissions). Never query projects/activities with `findAll()` or a
  raw query builder — that leaks projects the user can't see. The same list validates the POST:
  an id not in `TargetResolver`'s lists resolves to `null`, never to an arbitrary entity.
- The UI shows **why** something was chosen (`SOURCE_NUMBER`/`TITLE`/`DESCRIPTION`/`RULE`). A new
  identification step needs its own source constant and label.
- Not identified → blank. Never fall back to "first project" or a default.

## 5. O que é listado (issue #1 DoD)

Never listed: all-day events (no `start.dateTime`), `eventType` in
`workingLocation`/`outOfOffice`/`birthday`/`fromGmail`, `status = cancelled`, deleted or
not-completed tasks. Listed or not by the user's settings: declined events (`skipDeclined`,
decided by the attendee with `self = true`) and "free" events (`skipFree`,
`transparency = transparent`).

Default description: `Participação na reunião '<título>'` (key `gcal.meeting_description`) when the
event has **other attendees that are not resources** (rooms don't make a meeting); plain title for
events without attendees and for tasks. Empty title → the translated `gcal.no_title`.

## 6. Tarefas não têm duração no Google

A completed task becomes a row that **ends** at `completed` and lasts the account's `taskDuration`
minutes. Google Tasks stores no duration or start time — don't invent one from `updated`/`due`.

## 7. Tempo, fuso e período

All dates shown and parsed in the **Kimai user's timezone** (`User::getTimezone()`), not the
server's nor Google's. The review form uses `Y-m-d\TH:i` in that timezone. The period is inclusive
of the `to` day (`until = to + 1 day`), defaults to Monday of the current week → today, and is
capped at **62 days** (`MAX_PERIOD_DAYS`).

## 8. Registro sempre pelo `TimesheetService` do Kimai

Timesheets are created only through `TimesheetService::saveNewTimesheet()` — it applies lockdown
periods, the overlapping-records setting, and the `create` permission check (added in Kimai 2.6x;
ADR-0005 explains why tests use the real class). Never `persist()` a `Timesheet` directly.

Errors are **per row** and the rest of the batch continues: `ValidationFailedException` messages go
to `ImportItem::$error`, and the table comes back with what the user typed preserved. Missing
project/activity and `end <= begin` are checked before calling Kimai. If a flush closes the
EntityManager, stop the loop — the remaining rows can't be saved.

## 9. OAuth: um cliente por instalação, somente leitura (ADR-0002)

- Client ID/Secret identify the **Kimai installation**, not the user; configured once by an admin
  in *Sistema → Configurações → Google Calendar*, with env vars `GOOGLE_CALENDAR_CLIENT_ID`/
  `_SECRET` as fallback — **system configuration wins**. Users never enter credentials.
- Scopes are exactly `openid email calendar.readonly tasks.readonly`. Adding a write scope, or any
  scope, is an ADR-level decision (users must re-consent; consent-screen verification may change).
- The Google Cloud setup guide is shown **only** with `is_granted('system_configuration')`;
  everyone else sees the usage guide or the "ask an administrator" notice.
- Callback `/google-calendar/oauth/callback` has **no locale** (Google requires a fixed URI); the
  locale travels in the session. Behind a proxy, `TRUSTED_PROXIES`/`TRUSTED_HOSTS` are required or
  the URI is built with `http://`.
- The `state` parameter is random per attempt, stored in session, compared with `hash_equals`, and
  removed on callback. Don't weaken this to "accept if missing".

## 10. Tokens e dados do usuário

- Access and refresh tokens are stored **only** through `TokenEncryptor` (libsodium secretbox, key
  derived from `APP_SECRET`). Never store, log, flash, dump or put a token in an exception message.
- `authorize` requires a refresh token (`access_type=offline`, `prompt=consent`); without one it
  fails with instructions rather than connecting half-way.
- A 401 or `invalid_grant` marks authorization lost → the account is disconnected locally.
  Disconnect revokes at Google best-effort; the local disconnect is what must always happen.
- A refreshed access token must be persisted (`loadItems` flushes in `finally`; the controller
  saves after listing calendars) — otherwise every request refreshes again.
- Every route requires `create_own_timesheet`, and the account is always loaded from
  `getUser()` — never from an id in the request. A user can never read another user's calendar,
  links or tokens.
- State-changing routes (disconnect, register) are POST with a CSRF token.
