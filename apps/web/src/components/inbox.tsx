import type { Task } from "@planner/api-client";
import { useQuery } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { forwardRef, useState } from "react";
import type { FormEvent } from "react";
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
import { Checkbox } from "@/components/ui/checkbox";
import { ApiError, queries, tasks, usePlannerMutation } from "@/lib/api";
import { isPast, shortDate, startOfToday } from "@/lib/time";
import { cn } from "@/lib/utils";

/**
 * Unscheduled tasks. Each card carries `data-task-id` and `data-duration`,
 * which is what WeekCalendar's Draggable reads when a card is dropped on the
 * grid. The whole panel is also the drop target for dragging an event back.
 */
export const Inbox = forwardRef<HTMLDivElement>(function Inbox(_, ref) {
    const { data: inbox = [], isPending } = useQuery(queries.inbox());
    const { data: done = [] } = useQuery(queries.doneSince(startOfToday()));
    const [deleting, setDeleting] = useState<Task | null>(null);

    const complete = usePlannerMutation((id: number) => tasks.complete(id));
    const reopen = usePlannerMutation((id: number) => tasks.reopen(id));
    const remove = usePlannerMutation((id: number) => tasks.remove(id));

    return (
        <aside ref={ref} className="flex w-80 shrink-0 flex-col border-r">
            <div className="flex items-baseline gap-2 px-5 pt-6 pb-3">
                <h2 className="text-lg font-semibold tracking-tight">Inbox</h2>
                <span className="text-sm text-muted-foreground tabular-nums">{inbox.length || ""}</span>
            </div>

            <AddTask />

            <div className="min-h-0 flex-1 overflow-y-auto px-3 pb-4">
                {inbox.map((task) => (
                    <InboxCard
                        key={task.id}
                        task={task}
                        onComplete={() => complete.mutate(task.id)}
                        onDelete={() => setDeleting(task)}
                    />
                ))}

                {!isPending && inbox.length === 0 && (
                    <p className="px-2 py-10 text-center text-sm text-muted-foreground">Nothing waiting. Nice.</p>
                )}

                {done.length > 0 && (
                    <div className="mt-6">
                        <p className="px-2 pb-1 text-xs font-medium text-muted-foreground">Done today</p>
                        {done.map((task) => (
                            <button
                                key={task.id}
                                type="button"
                                onClick={() => reopen.mutate(task.id)}
                                title="Reopen"
                                className="block w-full truncate rounded-md px-2 py-1 text-left text-sm text-muted-foreground line-through decoration-muted-foreground/50 hover:bg-accent"
                            >
                                {task.title}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            <AlertDialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete “{deleting?.title}”?</AlertDialogTitle>
                        <AlertDialogDescription>The task is gone for good. To finish it, tick it off instead.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && remove.mutate(deleting.id)}>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </aside>
    );
});

function InboxCard({ task, onComplete, onDelete }: { task: Task; onComplete: () => void; onDelete: () => void }) {
    return (
        <div
            data-task-id={task.id}
            data-title={task.title}
            data-duration={task.planned_duration_min}
            className="group flex cursor-grab items-start gap-2.5 rounded-md px-2 py-2 hover:bg-accent active:cursor-grabbing"
        >
            <Checkbox
                className="mt-0.5"
                checked={false}
                onCheckedChange={onComplete}
                aria-label={`Mark “${task.title}” done`}
            />
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm leading-5">{task.title}</p>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span className="tabular-nums">{task.planned_duration_min} min</span>
                    {task.deadline && (
                        <span className={cn(isPast(task.deadline) && "font-medium text-destructive")}>
                            due {shortDate(task.deadline)}
                        </span>
                    )}
                    {task.tags.map((tag) => (
                        <span key={tag}>#{tag}</span>
                    ))}
                </div>
            </div>
            <button
                type="button"
                onClick={onDelete}
                className="mt-0.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:text-foreground"
                aria-label={`Delete “${task.title}”`}
            >
                <Trash2 className="size-3.5" />
            </button>
        </div>
    );
}

/** A single quiet row, Notion style: type, optionally a length, press Enter. */
function AddTask() {
    const [title, setTitle] = useState("");
    const [minutes, setMinutes] = useState("");
    const [error, setError] = useState<string | null>(null);

    const create = usePlannerMutation((body: { title: string; duration_min: number | null }) => tasks.create(body));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!title.trim()) return;

        create.mutate(
            { title: title.trim(), duration_min: minutes ? Number(minutes) : null },
            {
                onSuccess: () => {
                    setTitle("");
                    setMinutes("");
                    setError(null);
                },
                onError: (e) => setError(e instanceof ApiError ? (e.field("title") ?? e.field("duration_min") ?? e.message) : null),
            },
        );
    };

    return (
        <form onSubmit={submit} className="px-3 pb-2">
            <div className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm focus-within:bg-accent hover:bg-accent">
                <Plus className="size-4 text-muted-foreground" />
                <input
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="New task"
                    className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted-foreground"
                    aria-label="New task title"
                />
                <input
                    value={minutes}
                    onChange={(e) => setMinutes(e.target.value.replace(/\D/g, ""))}
                    placeholder="min"
                    inputMode="numeric"
                    className="w-10 bg-transparent text-right text-xs tabular-nums outline-none placeholder:text-muted-foreground"
                    aria-label="Estimate in minutes"
                />
                {/* With two text inputs, Enter only submits if a submit button exists. */}
                <button type="submit" className="sr-only">
                    Add task
                </button>
            </div>
            {error && <p className="px-2 pt-1 text-xs text-destructive">{error}</p>}
        </form>
    );
}
