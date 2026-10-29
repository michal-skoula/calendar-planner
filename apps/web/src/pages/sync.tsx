import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import type { ReactNode } from "react";
import { PageHeader } from "@/components/app-shell";
import { queries } from "@/lib/api";
import { ago, clock } from "@/lib/time";
import { cn } from "@/lib/utils";

/**
 * What has been talking to this server, and what it changed. Clients poll on
 * their own schedule, so this page polls too.
 */
export function SyncPage() {
    const { data: clients = [] } = useQuery({ ...queries.syncClients(), refetchInterval: 15_000 });
    const { data: feeds = [] } = useQuery(queries.feeds());
    const { data: events = [] } = useQuery({ ...queries.syncEvents(), refetchInterval: 15_000 });

    return (
        <div className="h-full overflow-y-auto">
            <div className="mx-auto max-w-3xl px-8 py-14">
                <PageHeader
                    title="Sync"
                    description="Devices pull changes on their own schedule. There is no push, so the last check-in is the honest answer to whether a device is syncing."
                />

                <Section title="Devices">
                    {clients.map((client) => (
                        <Row key={client.id}>
                            <Dot ok={client.healthy} />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm">{client.agent}</p>
                                <p className="text-xs text-muted-foreground">
                                    checked in {ago(client.last_seen_at)} · {client.last_method} answered {client.last_status}
                                </p>
                            </div>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {client.request_count.toLocaleString()} requests
                            </span>
                        </Row>
                    ))}
                    {clients.length === 0 && <Empty>Nothing has connected over CalDAV yet.</Empty>}
                </Section>

                {feeds.length > 0 && (
                    <Section
                        title="Subscribed calendars"
                        action={
                            <Link to="/calendars" className="text-xs text-muted-foreground hover:text-foreground">
                                Manage
                            </Link>
                        }
                    >
                        {feeds.map((feed) => (
                            <Row key={feed.id}>
                                <span className="size-2 shrink-0 rounded-full" style={{ background: feed.color }} />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm">{feed.name}</p>
                                    <p className={cn("text-xs", feed.healthy ? "text-muted-foreground" : "text-destructive")}>
                                        {feed.status_line}
                                    </p>
                                </div>
                                {feed.payload_bytes !== null && (
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {Math.round(feed.payload_bytes / 1024)} KB
                                    </span>
                                )}
                            </Row>
                        ))}
                    </Section>
                )}

                <Section title="Activity">
                    {events.map((event) => (
                        <Row key={event.id}>
                            <span
                                className="w-10 shrink-0 text-xs text-muted-foreground tabular-nums"
                                title={new Date(event.occurred_at).toLocaleString()}
                            >
                                {clock(event.occurred_at)}
                            </span>
                            <p className="min-w-0 flex-1 text-sm">
                                {event.title && <span className="font-medium">{event.title} </span>}
                                <span className={event.failure ? "text-destructive" : "text-muted-foreground"}>{event.sentence}</span>
                                {event.detail && <span className="block text-xs text-muted-foreground">{event.detail}</span>}
                            </p>
                            <span className="shrink-0 text-xs text-muted-foreground">{ago(event.occurred_at)}</span>
                        </Row>
                    ))}
                    {events.length === 0 && <Empty>Nothing has changed yet.</Empty>}
                </Section>

                <p className="text-xs text-muted-foreground">
                    On macOS the refresh interval is in Calendar › Settings › Accounts. On iOS it is Settings › Apps ›
                    Calendar › Accounts › Fetch New Data.
                </p>
            </div>
        </div>
    );
}

function Section({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
    return (
        <section className="mb-10">
            <div className="mb-1 flex items-baseline justify-between border-b pb-2">
                <h2 className="text-sm font-medium text-muted-foreground">{title}</h2>
                {action}
            </div>
            <div className="divide-y">{children}</div>
        </section>
    );
}

function Row({ children }: { children: ReactNode }) {
    return <div className="flex items-center gap-3 py-2.5">{children}</div>;
}

function Dot({ ok }: { ok: boolean }) {
    return <span className={cn("size-2 shrink-0 rounded-full", ok ? "bg-success" : "bg-destructive")} />;
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="py-6 text-sm text-muted-foreground">{children}</p>;
}
