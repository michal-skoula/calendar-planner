# Planner API

The backend: a Laravel app that owns every task, serves the REST API the clients use, speaks
CalDAV to Apple Calendar, and exposes MCP tools. See the [repository README](../../README.md) for
how it fits with the web app.

A single-user, self-hosted task planner. Tasks live in an inbox; scheduling one puts it on a
calendar that Apple Calendar reads **and writes** over CalDAV. Change something in the browser
and it shows up on your phone; move an event on your phone and the task follows.

Runs at <https://planner.internal.michalskoula.com> on `cowork-server`, reachable over Tailscale
only.

```
REST API    ─┐   (web app, Shortcut, future desktop/mobile apps)
MCP tools   ─┴─> TaskService ─┐
                              ├─> TaskCalendarBackend ─┬─> calendarobjects   (iCalendar truth)
                              │   (the chokepoint)     └─> tasks             (task truth + span mirror)
Apple Calendar ─> sabre/dav ──┘
```

**v1 handles one-off tasks only.** No recurrence, and no per-occurrence completion.

---

## Reading the code

Start at a route and follow it down. Every layer is a plain class you can open:

| Where | What it is |
|---|---|
| [`routes/api.php`](routes/api.php) | Every REST endpoint, one line each. |
| [`openapi.yaml`](openapi.yaml) | The contract for those endpoints: fields, types, status codes, and the time conventions. |
| [`app/Http/Controllers/Api`](app/Http/Controllers/Api) | Validation and nothing else. Each action is a few lines that call a service. |
| [`app/Http/Resources`](app/Http/Resources) | Model to JSON. One class per schema in `openapi.yaml`. |
| [`app/Support/WallClock.php`](app/Support/WallClock.php) | The two time formats the API speaks, in one place. |
| [`app/Services/TaskService.php`](app/Services/TaskService.php) | Every task write. The controllers and the MCP tools both call it. |
| [`app/Dav`](app/Dav) | CalDAV: the backend subclass that is the only writer of calendar state, and the iCalendar mapping. |
| [`app/Feeds`](app/Feeds) | Subscribed calendars: fetching, the SSRF guard, recurrence expansion. |
| [`app/Mcp`](app/Mcp) | MCP tools. They call `TaskService` directly, not the REST API. |

A request to `POST /api/tasks/7/schedule` goes `routes/api.php` → `TaskController::schedule()`
(validates, parses wall clock) → `TaskService::schedule()` → `TaskCalendarBackend::createCalendarObject()`
(writes the `.ics` and mirrors the span onto `tasks`) → back up as a `TaskResource`.

### The REST API

Described in full in [`openapi.yaml`](openapi.yaml). Short version:

- `{"data": ...}` on success; `{"message"}` on an error, plus `errors` keyed by field on a 422.
- Planning times (`start`, `end`, the `from`/`to` filters) are naive wall clock in
  `PLANNER_TIMEZONE`, `2026-10-26T09:00:00`. Instants (`completed_at`, `last_seen_at`) are UTC with
  a `Z`. `GET /api/me` says which timezone the wall clock is in.
- The web app signs in with a session cookie (`POST /api/auth/login`, Sanctum's SPA mode). Any
  other client sends a bearer token, made on the Access tokens page (`/api/tokens`), with
  `php artisan planner:token`, or by trading the password at `POST /api/auth/tokens`.
- Clients poll `GET /api/revision` and refetch when it changes. That is how a change made on a
  phone over CalDAV reaches an open browser tab.

**The spec is enforced by the tests.** Any test class that uses
[`MatchesOpenApi`](tests/Concerns/MatchesOpenApi.php) has every `/api` response validated against
`openapi.yaml`. The schemas set `additionalProperties: false`, so a field added to a Resource but
not to the spec fails, and so does a status code the spec does not list.
[`OpenApiContractTest`](tests/Feature/Api/OpenApiContractTest.php) checks that the router and the
spec name the same endpoints. To change the API: edit `openapi.yaml`, run the tests, fix whatever
fails, then run `npm run generate` at the repository root so the TypeScript client picks it up.

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

Requires PHP 8.4 with `pdo_sqlite`, `intl` and `zip`; Composer. Run these in `apps/api`:

```bash
composer install
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

Then, from the repository root, `npm install && npm run dev` starts this API on :8100 and the web
app on :5180. Sign in at <http://localhost:5180>. `php artisan serve --port=8100` on its own is
enough if you only want the API.

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

**Give it the hostname alone, with no `/dav`.** That works because `/.well-known/caldav`
301-redirects to `/dav` ([`routes/web.php`](routes/web.php)); that route is excluded from CSRF in
[`bootstrap/app.php`](bootstrap/app.php) because RFC 6764 clients use `PROPFIND` there, not `GET`,
and a CalDAV client has no CSRF token.

The longer `planner.internal.michalskoula.com/dav` also works, but only because
[`DavPathAliasPlugin`](app/Dav/DavPathAliasPlugin.php) cleans up after it. Apple's client resolves
discovery against whatever path you type, so with `/dav` in the field it asks for
`/dav/.well-known/caldav`, and then — once the root hands back an href of `/dav/principals/…` —
for `/dav/dav/principals/…`. Both would 404 inside sabre, and **macOS reports that as "Unable to
verify account name or password"**, which sends you hunting for a credential problem that does not
exist. The plugin redirects both back to the right path, before authentication runs.

The device must be on the tailnet. There is no route to this host from anywhere else.

### Watching it sync

There is **no push**. Clients poll on a schedule they choose, so a task scheduled in the browser
does not reach the Mac until its next refresh — macOS sets this at Calendar › Settings › Accounts ›
Refresh Calendars, iOS at Settings › Apps › Calendar › Accounts › Fetch New Data.

Because of that, "when did it last check in" is the only honest answer to "is it syncing", and it
is the first thing on the web app's **Sync** page, linked from the status line at the bottom of
its sidebar, and on `GET /api/sync/clients`. The page lists every
client that has connected, when each last checked in and whether that request succeeded, then what
actually changed — each line saying whether it came from a device or from the planner — with
sign-in refusals and server errors called out in red.

Two tables back it ([`database/migrations/2026_10_24_120000_create_sync_tables.php`](database/migrations/2026_10_24_120000_create_sync_tables.php)).
`sync_clients` holds one upserted row per client, so it never grows with traffic and is safe to
touch on every request. `sync_events` holds only things worth reading — a task changed, or
something failed — because a row per poll would bury them. A device re-sending an unchanged event
records nothing. Events are pruned after 30 days.

Writes reach the backend from a device and from the planner's own code through the same methods,
so [`SyncLog`](app/Services/SyncLog.php) is told the client at the start of each DAV request and
cleared at the end; no client means our own code was writing. None of this may break a sync, so
every write is wrapped — a failure to record costs a log line and nothing else.

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

## Subscribed calendars

Other people's calendars, read-only, drawn behind the task grid. Add one on the web app's
**Calendars** page (`POST /api/feeds`) with its iCal URL; a `webcal://` link is accepted and rewritten. Nothing is ever written back, nothing on
the grid can be dragged or completed, and a subscribed calendar is never re-served over CalDAV —
Apple subscribes to an iCal URL natively and better.

The whole feature lives in [`app/Feeds`](app/Feeds) and one table, `calendar_feeds`. It touches
neither `tasks` nor any sabre table, so the invariant above does not apply to it and cannot be
broken by it.

**The read path never opens a socket.** Fetching happens in `planner:feeds:refresh`, on a timer on
the host, and writes one column. Rendering a week reads the last response body that parsed, so a
feed whose server is down, slow or gone leaves the grid exactly as it was — the only visible change
is a red dot and a line on the sync page. There is no timeout tuning here because there is no
timeout in the request path to tune.

Recurrence is expanded on read, for the visible window, and thrown away. Feeds need no occurrences
table for the reason tasks would: a feed occurrence is a rectangle, not something you can complete.

| Behaviour | Why it is like that |
|---|---|
| A failed fetch never clears `payload` | The alternative is a calendar that empties itself whenever someone else's server has a bad morning. |
| A 200 answering with HTML counts as a failure | An expired share link answers `200 text/html` with a sign-in page, which is indistinguishable from success at the HTTP layer. The body has to parse as a `VCALENDAR` before it may replace a good one. |
| Expansion is per series, not per document | `VCalendar::expand()` iterates every recurring UID in one loop, and `EventIterator` throws past `Settings::$maxRecurrences` (3500). Reaching today from a daily event created in 2015 costs about 4,000 iterations, so one such event would empty the whole feed. [`FeedExpander`](app/Feeds/FeedExpander.php) groups by UID and catches per series: the standup drops, the calendar renders, `/sync` names what was lost. |
| `Settings::$maxRecurrences` is set to `-1` and restored in `finally` | It is a process-wide static and FrankenPHP keeps the worker, so leaking it would mean later DAV writes running with no recurrence limit. A per-series iteration budget replaces it. |
| Event ids are `feed-{id}-{hash}-{occurrence}` | Tasks and feed events share one grid. A numeric feed id would be one careless client away from completing whichever task shared that number. |
| The URL is stored encrypted | A Google or iCloud feed URL is a bearer credential: holding it is reading the calendar. Duplicates are caught on `url_hash` instead. |
| The URL is resolved and range-checked before every fetch, and on every redirect hop | This container shares a compose bridge with Caddy, n8n and OpenWebUI, and the box is on a tailnet, so a pasted URL is a way to ask the server to fetch what the person typing it cannot reach. Loopback, RFC1918, link-local, CGNAT and the tailnet range are refused. |

### Configuration

| Variable | Default | What it does |
|---|---|---|
| `PLANNER_FEED_REFRESH_MIN` | `60` | Minutes between fetches of one feed. The timer fires every 15 minutes and skips feeds that are not due. |
| `PLANNER_FEED_TIMEOUT` | `10` | Seconds, connect and total. Nothing waits on this. |
| `PLANNER_FEED_MAX_BYTES` | `2097152` | Ceiling on a response body, enforced while streaming. Every byte under it ends up in the backup tarball. |
| `PLANNER_FEED_CACHE_TTL` | `86400` | Housekeeping only; expansions are keyed on the payload hash, so a stale entry is unreachable rather than wrong. |
| `PLANNER_FEED_ALLOW_PRIVATE` | `false` | **Local development only.** Allows `http://` and private addresses. On the box this would let a pasted URL reach anything on the compose bridge. |

---

## Access tokens

Everything that is not the web app (the iOS Shortcut, MCP clients, scripts) authenticates with a
bearer token. Create one on the web app's **Access tokens** page: give it a name, pick an expiry
(30 days, 90 days, a year, a date of your choosing, or never), and copy the secret from the dialog.
It is shown once; Sanctum keeps only a SHA-256 hash. The same page lists each token's last use and
revokes it.

A token has the same access as the account. It cannot create, list or revoke tokens, though:
[`RequireBrowserSession`](app/Http/Middleware/RequireBrowserSession.php) refuses those requests
when they carry a bearer token, so a leaked token cannot mint itself a replacement before you
revoke it.

Without a browser, from the box:

```bash
php artisan planner:token shortcut --expires=2027-06-30
```

## The iOS Shortcut

Create a token named for it, as above.

Then build a Shortcut with a **Get Contents of URL** action:

- URL `https://planner.internal.michalskoula.com/api/tasks` (the same `createTask` endpoint the web
  app uses)
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

Returns `201` with the created task under `data`. The quickest useful version is a single text input wired to
`title`, so dictating into it drops a task in the inbox.

---

## MCP

Eight tools over `TaskService`, called directly rather than through the REST API: `list_tasks`, `get_task`, `create_task`, `update_task`,
`schedule_task`, `unschedule_task`, `complete_task`, `delete_task`. Datetimes cross the boundary
as naive local wall clock, so a value read from one tool goes straight into another.

**Claude Code / Claude Desktop, over HTTP** — with a bearer token of its own from the Access
tokens page:

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

The image is built from the whole repository: the [`Dockerfile`](../../Dockerfile) at the root builds
the web app, then copies its output into this app's `public/`, so the browser loads the page and
calls the API on one origin. Run these from the repository root.

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

### Refreshing subscribed calendars

`planner-feeds.timer` fires every 15 minutes with a randomised delay and runs
`planner:feeds:refresh` inside the container. The command skips feeds that are not due by their own
`refresh_min`, so the schedule lives in the database rather than in a unit file that would need
editing to change it. Install the two units the same way as the backup pair:

```bash
scp deploy/planner-feeds.{service,timer} michal@cowork-server:/tmp/
ssh michal@cowork-server 'sudo install -m644 /tmp/planner-feeds.service /tmp/planner-feeds.timer /etc/systemd/system/ \
  && sudo systemctl daemon-reload && sudo systemctl enable --now planner-feeds.timer'
```

By hand, including feeds that are not due:

```bash
docker compose -f /opt/planner/compose.yml exec planner php artisan planner:feeds:refresh --force
```

It exits non-zero only when every feed it tried failed, so one provider having a bad morning does
not make the timer unit look broken.

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
- A token works until its expiry date runs out (end of that day in Prague) or it is revoked, and a
  bearer token can never create or list tokens.
- Every `/api` response a test receives matches `openapi.yaml`, and the router and the spec list
  the same endpoints.
- The web app's routes get the built page, while `/api`, `/dav`, `/up` and `/.well-known` are never
  shadowed by it, and an unknown `/api` path is a JSON 404.
- `schedule_task` on an already-scheduled task moves it instead of creating a second event.

For subscribed calendars, with fixtures in [`tests/Fixtures/feeds`](tests/Fixtures/feeds):

- A daily series that started in 2015 still renders this week — and a test asserts that sabre's own
  `expand()` throws on the same fixture, so nobody switches back to it by accident.
- An `EXDATE`'d occurrence is gone; a `RECURRENCE-ID` override appears once, at its moved time,
  with its own title, and the slot it left is empty.
- One series with a rule the library cannot read drops alone and the rest of the feed renders; a
  series that outruns the iteration budget does the same instead of hanging.
- `Settings::$maxRecurrences` is back to 3500 after an expansion that threw.
- A floating `DTSTART` lands at that wall clock in Prague; an all-day span keeps its dates with an
  exclusive end; a `STATUS:CANCELLED` event is not drawn.
- A 304, a 503, an HTML sign-in page and a body over the cap all leave the stored calendar intact,
  and the three failures are recorded on the sync page.
- The second fetch sends `If-None-Match` and `If-Modified-Since`; an identical body with no
  validators is treated as the 304 it should have been.
- A URL resolving onto this network is refused before any request is sent, from the fetcher and
  from `POST /api/feeds`.
- Rendering a week sends no HTTP request at all.
- No feed event id casts to an existing task id, and every feed event is returned non-editable.
- Hiding a feed removes its events without discarding its payload.
- A payload rewritten in the same second as the previous one still changes `GET /api/revision`.

---

## Known limits

- **No recurrence in tasks.** One task per UID holds only while every event is one-off. Adding RRULE
  means a task can have many occurrences, which is where per-occurrence completion lands. Expect an
  occurrences table and a rewrite of the mirror, not a new column. Subscribed calendars *do* expand
  recurrence, and are no precedent for this: they are read-only, so an occurrence there is a
  rectangle rather than something to complete, and it never needs storing.
- **Subscribed calendars carry only what the grid draws**: title, span, location and busy state. No
  attendees, no RSVP, no alarms. Adding any of them is additive on `FeedOccurrence`.
- **A feed's own refresh is a pull on a timer**, so the grid can be up to `PLANNER_FEED_REFRESH_MIN`
  minutes behind. There is no push, and no provider offers one.
- **The SSRF guard resolves a name, then the HTTP client resolves it again.** A host answering with
  two different addresses can get through that window. Closing it means pinning the connection to
  the address that was checked, which is more machinery than one person's own URLs justify.
- **Last write wins.** No ETag preconditions on our own writes. Two near-simultaneous changes to one
  task leave whichever arrived second. Fine for one person with one phone.
- **Completion cannot start on the phone.** Nothing in Apple Calendar marks a VEVENT done.
- **Tailnet only**, so no claude.ai connector and no inbound webhooks.
- Backups sit on the same disk as the database, so a disk loss takes both.
