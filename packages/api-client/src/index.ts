/**
 * The planner API client, shared by every TypeScript front end (the web app
 * now, an Electron or Tauri shell later).
 *
 * `schema.d.ts` is generated from apps/api/openapi.yaml by `npm run generate`,
 * so request bodies, query parameters and responses are all typed from the
 * spec. Nothing in here is hand-written per endpoint.
 */
import createClient, { type Middleware } from "openapi-fetch";
import type { components, paths } from "./schema";

export type { components, paths };

export type Task = components["schemas"]["Task"];
export type Feed = components["schemas"]["Feed"];
export type FeedEvent = components["schemas"]["FeedEvent"];
export type SyncClient = components["schemas"]["SyncClient"];
export type SyncEvent = components["schemas"]["SyncEvent"];
export type User = components["schemas"]["User"];
export type AccessToken = components["schemas"]["AccessToken"];

export type PlannerClient = ReturnType<typeof createPlannerClient>;

export interface ClientOptions {
    /** Origin of the API. Empty for the web app, which shares an origin with it. */
    baseUrl?: string;
    /**
     * A bearer token, for clients that cannot hold a cookie. Without one the
     * client uses the browser session, which is what the web app does.
     */
    token?: () => string | null | undefined;
}

/** An error response from the API, with Laravel's per-field messages on a 422. */
export class ApiError extends Error {
    constructor(
        readonly status: number,
        message: string,
        readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }

    /** The first message for a field, for showing under an input. */
    field(name: string): string | undefined {
        return this.errors[name]?.[0];
    }
}

export function createPlannerClient(options: ClientOptions = {}) {
    const client = createClient<paths>({
        baseUrl: options.baseUrl ?? "",
        credentials: "include",
        headers: { Accept: "application/json" },
    });

    client.use(auth(options));
    client.use(throwOnError);

    return client;
}

/**
 * Laravel's CSRF check for cookie sessions: the server sets an XSRF-TOKEN
 * cookie, and every write has to echo it back in a header.
 */
function auth(options: ClientOptions): Middleware {
    return {
        async onRequest({ request }) {
            const token = options.token?.();

            if (token) {
                request.headers.set("Authorization", `Bearer ${token}`);
                return request;
            }

            if (request.method !== "GET" && !readCookie("XSRF-TOKEN")) {
                await fetch(`${options.baseUrl ?? ""}/sanctum/csrf-cookie`, { credentials: "include" });
            }

            const xsrf = readCookie("XSRF-TOKEN");
            if (xsrf) request.headers.set("X-XSRF-TOKEN", xsrf);

            return request;
        },
    };
}

/** Turn every non-2xx into a thrown ApiError, so callers only handle success. */
const throwOnError: Middleware = {
    async onResponse({ response }) {
        if (response.ok) return response;

        const body = await response
            .clone()
            .json()
            .catch(() => ({}));

        throw new ApiError(response.status, body.message ?? response.statusText, body.errors ?? {});
    },
};

function readCookie(name: string): string | null {
    if (typeof document === "undefined") return null;

    const match = document.cookie.split("; ").find((row) => row.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}
