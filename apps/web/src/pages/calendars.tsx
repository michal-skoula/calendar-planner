import type { Feed } from "@planner/api-client";
import { useQuery } from "@tanstack/react-query";
import { MoreHorizontal } from "lucide-react";
import { useState } from "react";
import type { FormEvent } from "react";
import { toast } from "sonner";
import { PageHeader } from "@/components/app-shell";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError, feeds, queries, usePlannerMutation } from "@/lib/api";
import { cn } from "@/lib/utils";

/**
 * Calendars someone else owns, read over iCal and drawn behind the tasks.
 * Nothing here is ever written back.
 */
export function CalendarsPage() {
    const { data: list = [], isPending } = useQuery(queries.feeds());
    const { data: me } = useQuery(queries.me());
    const [removing, setRemoving] = useState<Feed | null>(null);

    const remove = usePlannerMutation((id: number) => feeds.remove(id));

    return (
        <div className="h-full overflow-y-auto">
            <div className="mx-auto max-w-3xl px-8 py-14">
                <PageHeader
                    title="Calendars"
                    description={`Read-only. Nothing here can be dragged or completed, and each one is re-fetched every ${me?.feed_refresh_min ?? 60} minutes.`}
                />

                <Subscribe />

                <div className="mt-10 divide-y border-t">
                    {list.map((feed) => (
                        <FeedRow key={feed.id} feed={feed} onRemove={() => setRemoving(feed)} />
                    ))}
                    {!isPending && list.length === 0 && (
                        <p className="py-6 text-sm text-muted-foreground">No subscribed calendars yet.</p>
                    )}
                </div>
            </div>

            <AlertDialog open={removing !== null} onOpenChange={(open) => !open && setRemoving(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove “{removing?.name}”?</AlertDialogTitle>
                        <AlertDialogDescription>Its events disappear from the planner. You can subscribe again any time.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => removing && remove.mutate(removing.id)}>Remove</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

function Subscribe() {
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});

    const create = usePlannerMutation((body: { url: string; name: string | null; color: string }) => feeds.create(body));

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const formEl = event.currentTarget;
        const form = new FormData(formEl);

        create.mutate(
            {
                url: String(form.get("url")),
                name: String(form.get("name")) || null,
                color: String(form.get("color")),
            },
            {
                onSuccess: (response) => {
                    const feed = (response as { data?: { data: Feed } }).data?.data;
                    setErrors({});
                    formEl.reset();
                    if (feed?.last_error) toast.warning(`Subscribed, but the first fetch failed: ${feed.last_error}`);
                    else toast.success(`Subscribed to ${feed?.name}.`);
                },
                onError: (e) => {
                    if (e instanceof ApiError) setErrors({ url: e.field("url"), name: e.field("name"), color: e.field("color") });
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="space-y-1.5">
                <Label htmlFor="feed-url">iCal URL</Label>
                <Input
                    id="feed-url"
                    name="url"
                    required
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="https://calendar.google.com/calendar/ical/…/basic.ics"
                    className="font-mono text-xs"
                />
                {errors.url ? (
                    <p className="text-xs text-destructive">{errors.url}</p>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        A webcal:// link works too. Anyone with this URL can read the calendar, so it is stored encrypted.
                    </p>
                )}
            </div>

            <div className="flex items-end gap-3">
                <div className="flex-1 space-y-1.5">
                    <Label htmlFor="feed-name">Name</Label>
                    <Input id="feed-name" name="name" placeholder="Optional" autoComplete="off" />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="feed-color">Colour</Label>
                    <input
                        id="feed-color"
                        name="color"
                        type="color"
                        defaultValue="#6b7280"
                        className="block h-9 w-12 cursor-pointer rounded-md border bg-transparent p-1"
                    />
                </div>
                <Button type="submit" disabled={create.isPending}>
                    {create.isPending ? "Fetching…" : "Subscribe"}
                </Button>
            </div>
        </form>
    );
}

function FeedRow({ feed, onRemove }: { feed: Feed; onRemove: () => void }) {
    const [editing, setEditing] = useState(false);

    const update = usePlannerMutation((body: { name?: string; color?: string; enabled?: boolean }) => feeds.update(feed.id, body));
    const refresh = usePlannerMutation(() => feeds.refresh(feed.id));

    if (editing) {
        return (
            <form
                className="flex items-center gap-2 py-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    const form = new FormData(event.currentTarget);
                    update.mutate(
                        { name: String(form.get("name")), color: String(form.get("color")) },
                        { onSuccess: () => setEditing(false) },
                    );
                }}
            >
                <input
                    name="color"
                    type="color"
                    defaultValue={feed.color}
                    aria-label="Colour"
                    className="h-8 w-9 cursor-pointer rounded-md border bg-transparent p-0.5"
                />
                <Input name="name" defaultValue={feed.name} autoFocus className="h-8 flex-1" aria-label="Name" />
                <Button type="submit" size="sm">
                    Save
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(false)}>
                    Cancel
                </Button>
            </form>
        );
    }

    return (
        <div className={cn("flex items-center gap-3 py-3", !feed.enabled && "opacity-50")}>
            <span className="size-2.5 shrink-0 rounded-sm" style={{ background: feed.color }} />
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm">
                    {feed.name}
                    {!feed.enabled && <span className="ml-2 text-xs text-muted-foreground">hidden</span>}
                </p>
                <p className={cn("text-xs", feed.enabled && !feed.healthy ? "text-destructive" : "text-muted-foreground")}>
                    {refresh.isPending ? "Fetching…" : feed.status_line}
                </p>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" aria-label={`Actions for ${feed.name}`}>
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => refresh.mutate(undefined)}>Refresh now</DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setEditing(true)}>Rename or recolour</DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => update.mutate({ enabled: !feed.enabled })}>
                        {feed.enabled ? "Hide" : "Show"}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" onSelect={onRemove}>
                        Remove
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
