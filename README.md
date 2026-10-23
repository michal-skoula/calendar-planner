# Planner

A single-user, self-hosted task planner. Tasks live in an inbox; scheduling one puts it on a
calendar that Apple Calendar reads **and writes** over CalDAV. Change something in the browser
and it shows up on your phone; move an event on your phone and the task follows.

Runs at <https://planner.internal.michalskoula.com> on `cowork-server`, reachable over Tailscale
only.

```
Livewire UI ─┐
MCP tools   ─┼─> TaskService ─┐
Shortcut    ─┘                ├─> TaskCalendarBackend ─┬─> calendarobjects   (iCalendar truth)
                              │   (the chokepoint)     └─> tasks             (task truth + span mirror)
Apple Calendar ─> sabre/dav ──┘
```

**v1 handles one-off tasks only.** No recurrence, and no per-occurrence completion.

---

## How it hangs together

Everything that can change whether a task is scheduled goes through one subclass of sabre's PDO
CalDAV backend, [`app/Dav/TaskCalendarBackend.php`](app/Dav/TaskCalendarBackend.php). Our own
writes arrive there through [`TaskService`](app/Services/TaskService.php); Apple's arrive through
sabre's server. Both paths converge before either table is touched.

The rule that keeps the two stores in step:

> `tasks.ical_uid`, `status`, `starts_at`, `ends_at` and `is_all_day` are written in exactly three
> methods — the `create`, `update` and `delete` overrides on `TaskCalendarBackend`. Nowhere else,
> in any layer.

There are deliberately **no model observers** on `Task`. That is what makes a write loop between
`tasks` and `calendarobjects` structurally impossible rather than merely unlikely. If you add an
observer that writes any of those five columns, you have broken the design.

A few consequences worth knowing before you change anything:

| Behaviour | Why it is like that |
|---|---|
| Deleting an event on your phone **unschedules** the task, it does not delete it | Title, notes, tags, deadline and estimate are the part you would not want to lose to a swipe. A finished task stays finished; an unfinished one returns to the inbox. |
| Rescheduling mints a **fresh UID** | So there is never a stale calendar object for iOS to resurrect. |
| Editing a title re-serialises the **existing** `.ics` | `VEventMapper::toVEvent()` mutates properties in place. Rebuilding from the task row would strip the `VALARM`, `LOCATION` and `X-APPLE-*` values Apple attached, so renaming a task in the browser would silently delete the alarm you set on your phone. |
| A done task's `SUMMARY` gains a `✅ ` prefix | iCalendar has no way to mark a VEVENT done. Configurable via `CALDAV_DONE_PREFIX`. Completion can only *originate* in the browser, MCP or a Shortcut — there is nothing in Apple Calendar that marks an event done. Clearing the prefix on the phone does reopen the task. |
| Our payloads carry `X-PLANNER-TASK-ID` | On a task's first schedule the UID is not persisted yet (the rule above forbids it), so the backend needs another way to recognise its own write. The hint is honoured only while the named task is unscheduled, so duplicating one of our events on a device creates a second task instead of hijacking the first. |
| All-day events are tolerated, never offered | The UI only creates timed blocks, but the backend parses `DTSTART;VALUE=DATE` correctly so an all-day event made on iOS cannot corrupt a task. `ends_at` is the **exclusive** end, matching both iCalendar and FullCalendar. |
| Times are stored in UTC, emitted as `Z` | One-off events only, so there is no DST expansion to get wrong and no `VTIMEZONE` to ship. The browser and the MCP tools both see naive wall clock in `PLANNER_TIMEZONE`. |

The sabre tables are [sabre's stock schema](database/migrations/2026_10_23_190100_create_sabre_dav_tables.php),
column for column. Nothing outside `app/Dav` may read or write them, and no Eloquent model is
mapped onto them. `calendarsubscriptions` and `schedulingobjects` are present even though this app
exposes neither: the stock backend implements `SubscriptionSupport` and `SchedulingSupport`, so
`CalendarHome` always lists an inbox and an outbox and Apple PROPFINDs all of it while adding an
account. Without those tables the calendar cannot be enumerated at all.

---

## Local setup

Requires PHP 8.4 with `pdo_sqlite`, `intl` and `zip`; Composer; Node 22+.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

Then create the account:

```bash
php artisan planner:user     # prompts for email + password
```

That is the only credential there is. The same email and password sign in to the web UI and to
CalDAV, and the email is also the DAV principal (`principals/{email}`), so `planner:user`
provisions the principal and the `Tasks` calendar as part of the same run — there is no separate
seeding step and no CalDAV app password.

Changing the email **moves** the principal, and the calendar moves with it. The account URL
changes with it too, so any device already syncing has to re-add the account.

Build and run:

```bash
npm run build
php artisan serve
```

Sign in at <http://localhost:8000>.

### Configuration

All of this lives in [`config/caldav.php`](config/caldav.php) and is settable from `.env`:

| Variable | Default | What it does |
|---|---|---|
| `CALDAV_REALM` | `Tasks` | Named in the Basic auth challenge. |
| `CALDAV_CALENDAR_URI` | `tasks` | Path segment Apple sees. **Do not change it after a device has added the account.** |
| `CALDAV_CALENDAR_NAME` | `Tasks` | Display name on the device. |
| `CALDAV_DONE_PREFIX` | `✅ ` | Prefix marking a finished task in the event summary. |
| `PLANNER_TIMEZONE` | `Europe/Prague` | Wall clock the browser and the MCP tools use. Storage is always UTC. |
| `PLANNER_DEFAULT_DURATION` | `30` | Block length for a task with no estimate of its own. |
| `DAV_LOG_LEVEL` | `info` | Verbosity of the DAV request log. `info` logs every request; `warning` only the failures. |

There is deliberately no CalDAV username or password here. Identity is a row in `users`, not
configuration, which is what makes the two logins one credential.

---

## Adding the calendar to Apple Calendar

**iOS** — Settings → Apps → Calendar → Calendar Accounts → Add Account → Other → Add CalDAV
Account:

| Field | Value |
|---|---|
| Server | `planner.internal.michalskoula.com` |
| User Name | your planner email, e.g. `michal@skoula.com` |
| Password | your planner password — the same one the web UI uses |

**macOS** — Calendar → Settings → Accounts → + → Other CalDAV Account → Account Type: Manual, same
three fields.

The address is matched case-insensitively, so iOS capitalising the first letter does no harm.

The hostname alone is enough because `/.well-known/caldav` 301-redirects to `/dav`
([`routes/web.php`](routes/web.php)); that route is excluded from CSRF in
[`bootstrap/app.php`](bootstrap/app.php) because RFC 6764 clients use `PROPFIND` there, not `GET`,
and a CalDAV client has no CSRF token. If a device refuses the short form, give it the full server
path `planner.internal.michalskoula.com/dav` instead.

The device must be on the tailnet. There is no route to this host from anywhere else.

### If a device will not connect

First read the log — every DAV request is in it, with the status it answered and the principal
sabre resolved:

```bash
ssh cowork-agent 'sudo docker compose -f /opt/planner/compose.yml logs -f planner' | grep DAV
```

```
DAV PROPFIND /dav/calendars/michal@skoula.com/tasks -> 207 (principal=principals/michal@skoula.com auth=basic agent=iOS/26.1 8ms)
CalDAV auth refused for michal@skoula.com: wrong password
```

A refused line names the address attempted and why — unknown account or wrong password, never the
password itself. `principal=-` on an authenticated request means auth passed but sabre resolved
nobody. Set `DAV_LOG_LEVEL=info` while you are debugging and put it back to `warning` afterwards.

Then work down this list; the discovery chain is the part that actually goes wrong. Export your
credentials first so they stay out of your shell history:

```bash
read -r EMAIL; read -rs PASSWORD      # or: export PLANNER_AUTH="email:password"
AUTH="$EMAIL:$PASSWORD"

# 1. Is the challenge Basic? If it says Bearer, the wrong auth backend is wired up.
curl -i -X PROPFIND https://planner.internal.michalskoula.com/dav | grep -i www-authenticate
#    -> WWW-Authenticate: Basic realm="Tasks"

# 2. Do the credentials work at all?
curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" \
  -X PROPFIND -H 'Depth: 0' https://planner.internal.michalskoula.com/dav
#    -> 207

# 3. Does discovery resolve? These two must name a principal, then a calendar home.
curl -s -u "$AUTH" -X PROPFIND -H 'Depth: 0' -H 'Content-Type: application/xml' \
  --data-binary '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>' \
  https://planner.internal.michalskoula.com/dav
curl -s -u "$AUTH" -X PROPFIND -H 'Depth: 0' -H 'Content-Type: application/xml' \
  --data-binary '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-home-set/></d:prop></d:propfind>' \
  "https://planner.internal.michalskoula.com/dav/principals/$EMAIL"

# 4. Does the calendar home enumerate? Expect tasks/, inbox/ and outbox/, all 207.
for c in "" tasks inbox outbox; do
  printf '%-8s %s\n' "${c:-home}" "$(curl -s -o /dev/null -w '%{http_code}' -u "$AUTH" \
    -X PROPFIND -H 'Depth: 1' "https://planner.internal.michalskoula.com/dav/calendars/$EMAIL/$c")"
done
```

A 500 anywhere in step 4 is the usual cause of the "account added but nothing syncs" symptom, and
iOS reports it to you as nothing more useful than an inactive account. Check the DAV log:

```bash
ssh cowork-agent 'sudo docker compose -f /opt/planner/compose.yml logs --tail=100 planner'
```

---

## The iOS Shortcut

Mint a bearer token:

```bash
php artisan planner:token shortcut
```

Then build a Shortcut with a **Get Contents of URL** action:

- URL `https://planner.internal.michalskoula.com/api/tasks`
- Method `POST`
- Headers: `Authorization: Bearer <token>`, `Content-Type: application/json`
- Request Body → JSON

| Field | Required | Notes |
|---|---|---|
| `title` | yes | |
| `start` | no | ISO 8601. **Without** an offset it is read as wall clock in `PLANNER_TIMEZONE`; **with** one the offset is honoured. Omit it and the task lands in the inbox. |
| `duration_min` | no | 5–1440. Also becomes the block length when scheduled. |
| `deadline` | no | `YYYY-MM-DD`, independent of `start`. |
| `notes` | no | Becomes the event description. |
| `tags` | no | Array of strings. |

Returns `201` with the created task. The quickest useful version is a single text input wired to
`title`, so dictating into it drops a task in the inbox.

---

## MCP

Eight tools over `TaskService`: `list_tasks`, `get_task`, `create_task`, `update_task`,
`schedule_task`, `unschedule_task`, `complete_task`, `delete_task`. Datetimes cross the boundary
as naive local wall clock, so a value read from one tool goes straight into another.

**Claude Code / Claude Desktop, over HTTP** — uses the same bearer token as the Shortcut:

```bash
claude mcp add --transport http planner https://planner.internal.michalskoula.com/mcp/planner \
  --header "Authorization: Bearer <token>"
```

**Locally, over stdio** — no token needed:

```bash
php artisan mcp:start planner
```

Inspect the tool surface interactively with `php artisan mcp:inspector planner`.

> **claude.ai connectors cannot reach this host.** It is Tailscale-only, so MCP works from Claude
> Code and Claude Desktop but not from the web app. Putting it behind a public hostname is the only
> fix, and that is a deliberate non-goal for now.

---

## Deploying to cowork-server

The box already runs Caddy with a Let's Encrypt wildcard for `*.internal.michalskoula.com`, so
adding this service needs **no new DNS record and no new certificate** — just a Caddyfile matcher.
See the `fleet` skill for the box's conventions.

```bash
# 1. Ship the source (vendor/, node_modules/ and the local database are excluded).
rsync -az --delete --exclude-from=.rsyncignore ./ cowork-agent:/tmp/planner-src/
ssh cowork-agent 'sudo mkdir -p /opt/planner && sudo rsync -a --delete /tmp/planner-src/ /opt/planner/src/'

# 2. First time only: create the env file and the data directory.
ssh cowork-agent 'sudo install -m 600 /dev/null /opt/planner/.env && sudo mkdir -p /opt/planner/data'
#    Put APP_KEY and the rest in /opt/planner/.env — see .env.example.

# 3. Build and start.
ssh cowork-agent 'sudo docker compose -f /opt/planner/compose.yml up -d --build'

# 4. First time only: migrate and provision.
ssh cowork-agent 'sudo docker compose -f /opt/planner/compose.yml exec planner php artisan migrate --force'
ssh cowork-agent 'sudo docker compose -f /opt/planner/compose.yml exec -it planner php artisan planner:user'
#    planner:user provisions the DAV principal and the calendar itself, so there is nothing
#    else to seed. Add --replace if the database already holds more than one user row.
```

Caddy routes it by Host header. `/opt/caddy/Caddyfile` carries:

```caddy
@planner host planner.internal.michalskoula.com
handle @planner {
    reverse_proxy planner:80
}
```

and `/opt/caddy/compose.yml` declares `planner_default` as an external network. After changing
either, bring Caddy up last — it refuses to start if a stack it proxies has never been created,
which is the desired behaviour:

```bash
ssh cowork-agent 'sudo docker compose -f /opt/caddy/compose.yml up -d'
```

**The container publishes no host port.** Caddy reaches it over the compose bridge, exactly like
n8n and OpenWebUI. `ss -tln` showing anything for this service is a regression.

### Operations

```bash
ssh cowork-agent 'sudo docker compose -f /opt/planner/compose.yml ps'            # expect Up (healthy)
ssh cowork-agent 'sudo docker compose -f /opt/planner/compose.yml logs -f planner'
curl -sf https://planner.internal.michalskoula.com/up                            # health endpoint
```

Working on the box directly, `michal` is in the `docker` group, so no `sudo` is needed:

```bash
ssh michal@cowork-server
docker compose -f /opt/planner/compose.yml ps
docker compose -f /opt/planner/compose.yml exec planner php artisan planner:user
```

> `planner:user` writes to whichever database the command can see, and prints which one plus the
> URL to sign in at. Running it on your laptop creates a **local** user; that account does not
> exist on the deployed site, and the symptom is a password that looks simply wrong.

To deploy a change: repeat steps 1 and 3, then `php artisan migrate --force` if there are new
migrations. The image is built on the box, so `docker compose pull` does nothing here — you need
`up -d --build`.

### Backups

`planner-backup.timer` fires at 04:15 UTC (06:15 Prague), after Caddy's 04:00, so the container
stops do not land on four cores at once. `/usr/local/bin/planner-backup.sh` stops the container,
tars `data/` and `.env`, starts it again, and prunes past 14 days. Run it by hand any time.

It stops the container first on purpose: copying a live SQLite WAL can produce a tarball that will
not restore. **A restore needs the tarball and nothing else, because `.env` is inside it** — which
is also why `backups/` is `0700 root`. `APP_KEY` lives there, and the account's password hash is
in the database beside it.

The whole application state is one SQLite file, tasks and calendar objects together, so that one
tarball is a complete backup.

---

## Tests

```bash
php artisan test
```

What they actually pin down — the Apple-side cases drive the real backend with payloads shaped the
way iOS sends them:

- Scheduling creates exactly one calendar object and mirrors the span; our own `schedule()` does
  not produce a second task.
- An unseen VEVENT becomes a new scheduled task; a device copy of one of our events becomes a
  second task rather than hijacking the first.
- Apple moving an event updates only the span, leaving title, tags and deadline alone.
- Apple deleting an event returns the task to the inbox with its notes, tags, deadline and
  estimate intact — and a finished task stays finished.
- Renaming a task in the browser preserves a `VALARM` the phone added.
- Completing prefixes the summary once, twice does not double it, the original completion time
  survives, and reopening removes it.
- An all-day VEVENT from iOS yields a whole-day span with an exclusive end; dragging it into a
  timed slot converts it.
- An event with no DTEND still gets a usable block; an unparseable payload is rejected as 415
  rather than 500.
- The challenge is Basic (not Bearer), a wrong password is refused, and an unset password hash
  closes the account rather than opening it.
- The discovery chain resolves, the calendar advertises VEVENT only, and the scheduling
  collections enumerate instead of erroring.
- `/.well-known/caldav` redirects for both GET and PROPFIND.
- A PUT over HTTP advances the sync token and a sync-collection REPORT sees the change.
- The events feed returns Prague wall clock on both sides of the October DST change.
- A naive `start` on the Shortcut endpoint is read as local wall clock, not UTC.
- `schedule_task` on an already-scheduled task moves it instead of creating a second event.

---

## Known limits

- **No recurrence.** One task per UID holds only while every event is one-off. Adding RRULE means a
  task can have many occurrences, which is where per-occurrence completion lands. Expect an
  occurrences table and a rewrite of the mirror, not a new column.
- **Last write wins.** No ETag preconditions on our own writes. Two near-simultaneous changes to one
  task leave whichever arrived second. Fine for one person with one phone.
- **Completion cannot start on the phone.** Nothing in Apple Calendar marks a VEVENT done.
- **Tailnet only**, so no claude.ai connector and no inbound webhooks.
- Backups sit on the same disk as the database, so a disk loss takes both.
