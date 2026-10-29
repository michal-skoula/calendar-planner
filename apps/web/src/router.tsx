import { createRootRoute, createRoute, createRouter, Outlet, redirect } from "@tanstack/react-router";
import { AppShell } from "@/components/app-shell";
import { ApiError, queries, queryClient } from "@/lib/api";
import { CalendarsPage } from "@/pages/calendars";
import { LoginPage } from "@/pages/login";
import { PlannerPage } from "@/pages/planner";
import { SyncPage } from "@/pages/sync";
import { TokensPage } from "@/pages/tokens";

/**
 * Every route in the app, in one file.
 *
 *   /login       signed out only
 *   /            the planner: inbox beside the week
 *   /calendars   subscribed calendars
 *   /sync        what has been syncing
 *   /tokens      bearer tokens for the Shortcut, MCP and other clients
 *
 * The signed-in routes hang off a layout route that asks the API who you are
 * before anything renders, and sends you to /login on a 401.
 */
const rootRoute = createRootRoute({ component: Outlet });

const loginRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: "/login",
    beforeLoad: async () => {
        const me = await queryClient.fetchQuery(queries.me()).catch(() => null);
        if (me) throw redirect({ to: "/" });
    },
    component: LoginPage,
});

const appRoute = createRoute({
    getParentRoute: () => rootRoute,
    id: "app",
    beforeLoad: async () => {
        try {
            return { me: await queryClient.ensureQueryData(queries.me()) };
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) throw redirect({ to: "/login" });
            throw error;
        }
    },
    component: AppShell,
});

const plannerRoute = createRoute({ getParentRoute: () => appRoute, path: "/", component: PlannerPage });
const calendarsRoute = createRoute({ getParentRoute: () => appRoute, path: "/calendars", component: CalendarsPage });
const syncRoute = createRoute({ getParentRoute: () => appRoute, path: "/sync", component: SyncPage });
const tokensRoute = createRoute({ getParentRoute: () => appRoute, path: "/tokens", component: TokensPage });

const routeTree = rootRoute.addChildren([loginRoute, appRoute.addChildren([plannerRoute, calendarsRoute, syncRoute, tokensRoute])]);

export const router = createRouter({ routeTree, defaultPreload: "intent" });

declare module "@tanstack/react-router" {
    interface Register {
        router: typeof router;
    }
}
