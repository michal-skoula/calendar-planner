# Planner

A single-user, self-hosted task planner. Tasks live in an inbox; scheduling one puts it on a
calendar that Apple Calendar reads and writes over CalDAV.

This repository holds the backend and every client, side by side:

```
apps/
  api/              Laravel. The REST API, CalDAV, MCP, and all the rules.   → apps/api/README.md
  web/              React web app. Talks to the API and nothing else.        → apps/web/README.md
packages/
  api-client/       TypeScript client generated from apps/api/openapi.yaml.
```

```
web app ──┐                                   ┌─> TaskService ─> TaskCalendarBackend ─> SQLite
(desktop, ├─ REST API (openapi.yaml) ─> Laravel ┤
 mobile)  ┘                                   │   ▲
MCP tools ──────────────────────────────────> ┘   │
Apple Calendar ─ CalDAV ──────────────────────────┘
```

**The contract is [`apps/api/openapi.yaml`](apps/api/openapi.yaml).** The backend's tests validate
every API response against it, and `packages/api-client` generates its TypeScript types from it. A
new client (Electron, Tauri) depends on `@planner/api-client` and gets the whole API typed. The MCP
server is the exception on purpose: it lives inside the Laravel app and calls `TaskService`
directly.

## Running it

Requires PHP 8.4 (with `pdo_sqlite`, `intl`, `zip`), Composer and Node 22+.

```bash
cd apps/api && composer install && cp .env.example .env && php artisan key:generate
```

```bash
touch apps/api/database/database.sqlite && php apps/api/artisan migrate
```

```bash
php apps/api/artisan planner:user
```

```bash
npm install
```

```bash
npm run dev
```

That starts the API on <http://localhost:8100> and the web app on <http://localhost:5180>. Open the
web app; Vite forwards `/api` to Laravel, so the browser sees one origin, the same as in
production.

| Command (from the root) | What it does |
|---|---|
| `npm run dev` | API and web app together. |
| `npm test` | The backend's PHPUnit suite, including the OpenAPI contract checks. |
| `npm run generate` | Regenerate `packages/api-client/src/schema.d.ts` after editing `openapi.yaml`. |
| `npm run typecheck` | TypeScript across every package. |
| `npm run build` | Generate the client, then build the web app into `apps/web/dist`. |

## Changing the API

1. Edit `apps/api/openapi.yaml`.
2. Change the route, controller or Resource in `apps/api`.
3. `npm test`. A response that no longer matches the spec fails here.
4. `npm run generate`, then `npm run typecheck`. Every client call the change broke fails here.

## Deploying

One image built from the root [`Dockerfile`](Dockerfile): it builds the web app, then copies the
output into the Laravel app's `public/`, so page and API share an origin and the session cookie
needs no CORS. Deployment steps, CalDAV setup and operations are in
[`apps/api/README.md`](apps/api/README.md).
