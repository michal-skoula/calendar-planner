# Planner web app

React 19, Vite, [TanStack Router](https://tanstack.com/router) and
[TanStack Query](https://tanstack.com/query), [shadcn/ui](https://ui.shadcn.com) components on
Tailwind 4, and FullCalendar for the week grid. It holds no business rules: every decision is the
API's, and this app draws the result.

| File | What it does |
|---|---|
| `src/router.tsx` | Every route, and the sign-in gate. |
| `src/lib/api.ts` | Every API call and query key. Calls are typed from `openapi.yaml`. |
| `src/components/app-shell.tsx` | Sidebar, and the 20-second `GET /api/revision` poll that refetches when a phone changed something. |
| `src/components/inbox.tsx` | Inbox, quick add, done today. |
| `src/components/week-calendar.tsx` | The grid: drag on, move, resize, drag back, click to complete. |
| `src/pages/*` | Sign-in, Calendars, Sync. |
| `src/components/ui/*` | shadcn/ui components, copied in by its CLI. Edit freely. |

Run it from the repository root with `npm run dev`. Add a shadcn component with
`npx shadcn@latest add <name>` in this directory, then check that its import of `cn` reads
`@/lib/utils`. The CLI has written `from "cn"` here before.

The look is deliberately monochrome: shades of grey in `src/index.css`, with colour only for errors
and for the swatch of a subscribed calendar.
