# Kimai Google Calendar plugin

A [Kimai](https://www.kimai.org) 2 plugin that imports **Google Calendar events** and **completed Google Tasks** as timesheet records.

Each user connects their own Google account (OAuth 2, read-only access), picks a period and reviews the events and tasks in a table before anything is registered.

## Features

- Per-user connection to Google through OAuth 2. The plugin only requests read-only scopes, and tokens are stored encrypted with a key derived from `APP_SECRET`.
- The user picks a period and gets a review table of their events and completed tasks. For each row they can adjust:
  - start and end time;
  - project and activity, pre-filled when they can be identified (see [How project and activity are identified](#how-project-and-activity-are-identified));
  - description, pre-filled with `Participação na reunião '<title>'` for events with other attendees, and with the plain title for events without attendees and for tasks.
- Only checked rows are registered. Rows that fail validation are shown again with the error, keeping what the user typed.
- Items already registered show up read-only and cannot be registered twice.
- Some events are never listed: all-day events, working-location and out-of-office entries, and cancelled events. Declined events and events marked as "free" are skipped when the user turns on the matching option.
- Translations are included for English and Brazilian Portuguese.

## Requirements

- Kimai 2.x
- A Google Cloud project with an OAuth client

## Installation

```bash
cd /path/to/kimai/var/plugins/
git clone https://github.com/vonbraunlabs/kimai-google-calendar-plugin.git GoogleCalendarBundle

cd /path/to/kimai/
bin/console kimai:reload --env=prod
bin/console kimai:bundle:google-calendar:install
```

The directory **must** be named `GoogleCalendarBundle`.

The install command creates the tables `kimai2_google_calendar_accounts` and `kimai2_google_calendar_links`.

## Who does what

- **Administrator, once per Kimai installation:** registers Kimai as an app in Google Cloud and enters the Client ID and Client Secret in Kimai, as described in the next section. Google requires this registration for any app that reads calendars. The Client ID identifies the Kimai installation, not a user.
- **Each user, alone:** opens the plugin page, clicks **Connect with Google** and confirms on Google's consent screen. Users never need their own credentials.

The plugin page shows the Google Cloud setup guide only to users with the `system_configuration` permission. Other users see a short guide on connecting their account, or a notice to ask an administrator while the integration is not configured.

## Google Cloud configuration

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and create a project, or select an existing one.
2. Under *APIs & Services → Library*, enable the **Google Calendar API** and the **Google Tasks API**.
3. Under *APIs & Services → OAuth consent screen*, configure the consent screen:
   - Choose *Internal* for Google Workspace, or *External* otherwise.
   - With *External*, add the people who will connect as test users, or publish the app. Otherwise Google blocks their login.
   - Add the scopes `calendar.readonly`, `tasks.readonly`, `openid` and `email`.
4. Under *APIs & Services → Credentials*, create an **OAuth client ID** of type **Web application**. As the **Authorized redirect URI**, enter:

   ```
   https://<your-kimai-host>/google-calendar/oauth/callback
   ```

   Administrators can also copy the exact URI from the setup guide on the plugin page.
5. In Kimai, open **System → Settings → Google Calendar** and enter the *Client ID* and *Client Secret*.

   As an alternative, you can set the environment variables `GOOGLE_CALENDAR_CLIENT_ID` and `GOOGLE_CALENDAR_CLIENT_SECRET`. Values entered in the system settings take precedence.

## Usage

1. Open **Times → Google Calendar** and click **Connect with Google**.
2. Under *Import settings*, select the calendar and, optionally, write mapping rules.
3. Under *Register time from Google*, choose the period and click **Load**. The period defaults to the current week and can span up to 62 days.
4. Review the table, check the rows you want and click **Register selected**.

Rows are pre-selected only when both project and activity were identified and the item has already ended.

Only users with the `create_own_timesheet` permission can use the plugin. The project and activity lists contain only what the user may book on, following Kimai's team permissions.

### How project and activity are identified

The project is searched in this order, and the first hit wins:

1. project **number** (code) in the title;
2. project **name** in the title;
3. project **name** in the description;
4. the first **mapping rule** whose keyword appears in the title.

The activity is then searched the same way, among the activities of that project plus the global ones, if the project allows them.

- Numbers, names and rule keywords are matched as whole words and case-insensitively. For example, a project called "QA" does not match "Quarterly".
- When several names match, the longest one wins, so "ACME Website" beats "ACME".
- Anything that cannot be identified stays blank, and the user picks it in the table.

### Mapping rules

Write one rule per line. The keyword is matched against the title as a whole word and case-insensitively, like numbers and names: `QA` matches `[GPV0374][QA] Process setup` and `QA tests`, but not `QUALITY`. When several rules match, the first one wins. Project and activity can be given by name or by number:

```
# keyword => Project
# keyword => Project / Activity
# keyword => / Activity
Daily       => Internal / Meeting
ACME        => ACME Website
Code review => / Development
```

### Completed tasks

Each completed task becomes a row that ends at the completion time and lasts the configured *task duration*. Google Tasks does not store how long a task took.

Kimai's own validation still applies when registering: lockdown periods, the overlapping-records setting and so on.

## Uninstall

```bash
rm -rf var/plugins/GoogleCalendarBundle
bin/console kimai:reload --env=prod
```

Then drop the two tables listed above, and the table `kimai2_bundle_migration_google_calendar`.

## Development

Only Docker is needed.

```bash
# unit tests with the 80% line coverage gate (no database or Kimai needed)
docker compose run --rm gcal-test sh -c \
  'composer install -n && composer test-coverage && php .github/scripts/coverage-gate.php coverage/clover.xml 80'

# a local Kimai with the plugin baked in, at http://localhost:8001 (admin@example.com)
KIMAI_ADMIN_PASSWORD=choose-one docker compose up -d --build --wait
docker compose exec gcal-kimai bin/console kimai:bundle:google-calendar:install -n
```

The plugin is copied into the image, not mounted, so rebuild after each change (`docker compose up -d --build`). To connect Google locally, set `GOOGLE_CALENDAR_CLIENT_ID` and `GOOGLE_CALENDAR_CLIENT_SECRET`, and register `http://localhost:8001/google-calendar/oauth/callback` as a redirect URI.

Contribution workflow, test strategy and architecture decisions: [CLAUDE.md](CLAUDE.md) and [docs/adrs](docs/adrs/README.md).

## License

MIT
