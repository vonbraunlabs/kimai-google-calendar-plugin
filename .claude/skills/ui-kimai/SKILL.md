---
name: ui-kimai
description: Operationalizes ADR-0004 (the plugin UI follows Kimai's own Tabler theme, no visual identity of its own) plus the translation rules (en/pt_BR/pt parity, gcal.* keys, flash messages). Load before writing or reviewing any Twig template, Symfony form, menu entry, system-configuration field or translation file in this repo.
---

`docs/adrs/0004-ui-segue-o-tema-do-kimai.md` is the source of truth — this skill is the checklist
for applying it in code. It replaces the Karajan `identidade-visual` skill on purpose: the plugin
renders inside Kimai's layout, so a palette or font of its own would clash with the rest of Kimai
and break with Kimai's themes and dark mode.

## Before writing any UI code

1. Read ADR-0004 and look at the existing templates in `Resources/views/` — reuse their structure
   (`card` + `card-header`/`card-title`/`card-actions`, `table table-vcenter`, `<details>` panels
   for guides) instead of inventing a new layout.
2. If you need a component that isn't in the existing templates, find how Kimai itself renders it
   (`vendor/kimai/kimai/templates/`) and copy that pattern.

## Rules that are easy to get wrong

1. **Templates extend `base.html.twig` and fill `{% block main %}`**; controllers pass
   `page_setup` (`new PageSetup('Google Calendar')`). No standalone HTML page, no layout of our own.
2. **No CSS of our own**: no `<style>`, no stylesheet asset, no custom color, font or CSS
   variable. Colors come from Tabler classes (`text-secondary`, `bg-*-lt`, `btn-primary`,
   `alert-*`) so dark mode keeps working. Inline `style=` is allowed only for one-off layout
   (column `min-width`, `cursor`), never for color or typography.
3. **Icons**: the Font Awesome bundled with Kimai (`fas`/`far`/`fab fa-…`). No icon font or SVG
   set of our own.
4. **No JS dependency of our own.** Small inline behavior (select-all checkbox, filtering the
   activity select by project) is fine; a library or bundler is not.
5. **Every visible string is a translation key** under `gcal.` — in templates (`|trans`), in forms
   (`label`), in `SystemConfigurationSubscriber` and in `MenuSubscriber`. Hardcoded English or
   Portuguese text in a template is a bug. Exception: data coming from Google or Kimai (titles,
   project names) and technical identifiers.
6. **Flash messages** use Kimai's helpers (`flashSuccess`/`flashError`/`flashWarning`) with keys
   in `flashmessages.*.yaml`; parameters use `%name%` placeholders. Generic cases reuse Kimai's
   keys (`action.update.success`, `action.csrf.error`) instead of duplicating them. The OAuth
   callback route has no locale — set it from the session before flashing (see
   `callbackAction`). A `%reason%` is translated **before** it is passed (Kimai translates the
   reason again, but without parameters). Google failures: throw `GoogleApiException` with an
   English message (for logs) **and** a `translationKey`/`translationParameters`; the controller
   shows the translated reason through `describe()`. Never flash `$ex->getMessage()` directly.
7. **Translation parity**: every key exists in `en`, `pt_BR` **and** `pt`, in both `messages` and
   `flashmessages`. The UI follows the Kimai user's language; any other locale falls back to
   English (Kimai's `translator.fallbacks: [en]`), so `en` must always be complete. `pt` is a byte-identical copy of `pt_BR` (Kimai users with locale `pt` would
   otherwise see English). The check is in `pre-pr-check` Step 2b. Portuguese text is pt-BR, with
   accents; keep Kimai's own vocabulary ("registro de horas", "projeto", "atividade").
8. **Permission-dependent content is gated in the template with `is_granted()`**, matching the
   controller: the Google Cloud setup guide only for `system_configuration` (ADR-0002). Don't hide
   admin content with CSS or JS.
9. **Forms that change state** post with a CSRF token (`csrf_token('<intent>')`, checked with
   `isCsrfTokenValid` using the same intent). Rows of the review table are named
   `rows[<item key>][field]` — `applyInput` relies on that shape (rule 1 of
   `regras-de-importacao`).
10. **Dates and times** use Kimai's Twig filters (`date_short`, `date_time`, `duration`…) so they
    follow the user's locale and format settings, not a hardcoded `|date('d/m/Y')`.

## Verifying

- `Tests/Controller/GoogleCalendarControllerTest.php` renders the templates with a real Twig in
  memory: a new template or variable needs a test that renders it.
- Visual check in a real Kimai: `docker compose up -d --build --wait` → http://localhost:8001,
  in both light and dark theme and in `en` and `pt_BR`. Say so in the PR when a screen changed.

## When reviewing someone else's UI change

Check the rules above before anything else — a screen can look fine in light mode in English and
still violate them (hardcoded color, missing `pt` key, admin guide visible to regular users). Cite
the rule number when flagging a violation.
