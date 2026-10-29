import { ApiError, createPlannerClient } from "@planner/api-client";
import { QueryClient, queryOptions, useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

/**
 * Every server call the web app makes goes through here.
 *
 * `api` is the generated client; the paths and bodies below are type-checked
 * against apps/api/openapi.yaml. Queries are defined once as `queryOptions` so
 * a route loader and a component share the same cache entry.
 */
export const api = createPlannerClient();

export { ApiError };

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 10_000,
            retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2,
        },
    },
});

export const queries = {
    me: () =>
        queryOptions({
            queryKey: ["me"],
            queryFn: async () => (await api.GET("/api/me")).data!.data,
            staleTime: Infinity,
        }),

    inbox: () =>
        queryOptions({
            queryKey: ["tasks", "inbox"],
            queryFn: async () => (await api.GET("/api/tasks", { params: { query: { status: "inbox" } } })).data!.data,
        }),

    doneSince: (since: string) =>
        queryOptions({
            queryKey: ["tasks", "done", since],
            queryFn: async () =>
                (await api.GET("/api/tasks", { params: { query: { completed_after: since } } })).data!.data,
        }),

    window: (from: string, to: string) =>
        queryOptions({
            queryKey: ["tasks", "window", from, to],
            queryFn: async () => (await api.GET("/api/tasks", { params: { query: { from, to } } })).data!.data,
        }),

    feedEvents: (from: string, to: string) =>
        queryOptions({
            queryKey: ["feed-events", from, to],
            queryFn: async () => (await api.GET("/api/feed-events", { params: { query: { from, to } } })).data!.data,
        }),

    feeds: () =>
        queryOptions({
            queryKey: ["feeds"],
            queryFn: async () => (await api.GET("/api/feeds")).data!.data,
        }),

    syncClients: () =>
        queryOptions({
            queryKey: ["sync", "clients"],
            queryFn: async () => (await api.GET("/api/sync/clients")).data!.data,
        }),

    syncEvents: () =>
        queryOptions({
            queryKey: ["sync", "events"],
            queryFn: async () => (await api.GET("/api/sync/events")).data!.data,
        }),

    tokens: () =>
        queryOptions({
            queryKey: ["tokens"],
            queryFn: async () => (await api.GET("/api/tokens")).data!.data,
        }),

    revision: () =>
        queryOptions({
            queryKey: ["revision"],
            queryFn: async () => (await api.GET("/api/revision")).data!.data.revision,
            refetchInterval: 20_000,
            staleTime: 0,
        }),
};

/** Throw away everything a task or feed change could affect. */
export function invalidatePlanner(client: QueryClient = queryClient) {
    return Promise.all([
        client.invalidateQueries({ queryKey: ["tasks"] }),
        client.invalidateQueries({ queryKey: ["feed-events"] }),
        client.invalidateQueries({ queryKey: ["feeds"] }),
        client.invalidateQueries({ queryKey: ["sync"] }),
    ]);
}

/**
 * A mutation that refreshes the planner when it lands and shows a toast when
 * it fails. Validation errors are left to the form that made the request.
 */
export function usePlannerMutation<TVars>(fn: (vars: TVars) => Promise<unknown>) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: fn,
        onSettled: () => invalidatePlanner(client),
        onError: (error) => {
            if (error instanceof ApiError && error.status === 422) return;
            toast.error(error.message);
        },
    });
}

export const tasks = {
    create: (body: { title: string; duration_min?: number | null }) => api.POST("/api/tasks", { body }),
    schedule: (id: number, body: { start: string; end?: string; duration_min?: number | null }) =>
        api.POST("/api/tasks/{task}/schedule", { params: { path: { task: id } }, body }),
    unschedule: (id: number) => api.POST("/api/tasks/{task}/unschedule", { params: { path: { task: id } } }),
    complete: (id: number) => api.POST("/api/tasks/{task}/complete", { params: { path: { task: id } } }),
    reopen: (id: number) => api.POST("/api/tasks/{task}/reopen", { params: { path: { task: id } } }),
    remove: (id: number) => api.DELETE("/api/tasks/{task}", { params: { path: { task: id } } }),
};

export const feeds = {
    create: (body: { url: string; name?: string | null; color: string }) => api.POST("/api/feeds", { body }),
    update: (id: number, body: { name?: string; color?: string; enabled?: boolean }) =>
        api.PATCH("/api/feeds/{feed}", { params: { path: { feed: id } }, body }),
    refresh: (id: number) => api.POST("/api/feeds/{feed}/refresh", { params: { path: { feed: id } } }),
    remove: (id: number) => api.DELETE("/api/feeds/{feed}", { params: { path: { feed: id } } }),
};

export const accessTokens = {
    create: async (body: { name: string; expires_on?: string | null }) =>
        (await api.POST("/api/tokens", { body })).data!.data,
    revoke: (id: number) => api.DELETE("/api/tokens/{token}", { params: { path: { token: id } } }),
};
