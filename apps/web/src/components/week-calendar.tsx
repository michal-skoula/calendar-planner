import type { FeedEvent, Task } from "@planner/api-client";
import type { EventDropArg, EventInput, EventSourceInput } from "@fullcalendar/core";
import interactionPlugin, { Draggable } from "@fullcalendar/interaction";
import type { EventResizeDoneArg } from "@fullcalendar/interaction";
import FullCalendar from "@fullcalendar/react";
import timeGridPlugin from "@fullcalendar/timegrid";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import type { RefObject } from "react";
import { Button } from "@/components/ui/button";
import { queries, tasks, usePlannerMutation } from "@/lib/api";
import { wall } from "@/lib/time";
import { cn } from "@/lib/utils";

type Range = { from: string; to: string };

/**
 * The week grid. Two layers: tasks, which you can drag, resize, click to
 * complete and drag back to the inbox; and subscribed calendars, drawn behind
 * them and never editable.
 *
 * Both come from TanStack Query keyed on the visible range, so any mutation
 * that invalidates ["tasks"] redraws the grid with no extra wiring.
 */
export function WeekCalendar({ inboxRef, defaultDuration }: { inboxRef: RefObject<HTMLElement | null>; defaultDuration: number }) {
    const calendar = useRef<FullCalendar>(null);
    const [range, setRange] = useState<Range | null>(null);
    const [title, setTitle] = useState("");
    const [view, setView] = useState<"timeGridWeek" | "timeGridDay">("timeGridWeek");

    const taskQuery = useQuery({
        ...queries.window(range?.from ?? "", range?.to ?? ""),
        enabled: range !== null,
        placeholderData: keepPreviousData,
    });
    const feedQuery = useQuery({
        ...queries.feedEvents(range?.from ?? "", range?.to ?? ""),
        enabled: range !== null,
        placeholderData: keepPreviousData,
    });

    const schedule = usePlannerMutation((v: { id: number; start: string; end?: string; duration_min?: number | null }) =>
        tasks.schedule(v.id, { start: v.start, end: v.end, duration_min: v.duration_min }),
    );
    const unschedule = usePlannerMutation((id: number) => tasks.unschedule(id));
    const toggle = usePlannerMutation((task: { id: number; done: boolean }) =>
        task.done ? tasks.reopen(task.id) : tasks.complete(task.id),
    );

    // Inbox cards become draggable onto the grid.
    useEffect(() => {
        if (!inboxRef.current) return;

        const draggable = new Draggable(inboxRef.current, {
            itemSelector: "[data-task-id]",
            eventData: (el) => ({
                title: el.dataset.title,
                duration: { minutes: Number(el.dataset.duration) || defaultDuration },
            }),
        });

        return () => draggable.destroy();
    }, [inboxRef, defaultDuration]);

    const sources = useMemo<EventSourceInput[]>(
        () => [
            { id: "tasks", events: (taskQuery.data ?? []).map(taskEvent) },
            { id: "feeds", editable: false, events: (feedQuery.data ?? []).map(feedEvent) },
        ],
        [taskQuery.data, feedQuery.data],
    );

    // A move and a resize are the same write: a new span.
    const persistSpan = (info: EventDropArg | EventResizeDoneArg) => {
        const id = info.event.extendedProps.taskId as number | undefined;
        if (!id || !info.event.start || !info.event.end) return info.revert();

        schedule.mutate({ id, start: wall(info.event.start), end: wall(info.event.end) }, { onError: () => info.revert() });
    };

    const api = () => calendar.current?.getApi();

    return (
        <section className="flex min-w-0 flex-1 flex-col">
            <div className="flex items-center gap-2 px-5 pt-5 pb-3">
                <h2 className="mr-auto text-lg font-semibold tracking-tight">{title}</h2>

                <div className="flex rounded-md border p-0.5">
                    {(["timeGridWeek", "timeGridDay"] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            onClick={() => {
                                setView(v);
                                api()?.changeView(v);
                            }}
                            className={cn(
                                "rounded px-2.5 py-0.5 text-xs text-muted-foreground transition-colors hover:text-foreground",
                                view === v && "bg-accent font-medium text-foreground",
                            )}
                        >
                            {v === "timeGridWeek" ? "Week" : "Day"}
                        </button>
                    ))}
                </div>

                <Button variant="outline" size="sm" onClick={() => api()?.today()}>
                    Today
                </Button>
                <div className="flex">
                    <Button variant="ghost" size="icon" className="size-8" onClick={() => api()?.prev()} aria-label="Previous">
                        <ChevronLeft />
                    </Button>
                    <Button variant="ghost" size="icon" className="size-8" onClick={() => api()?.next()} aria-label="Next">
                        <ChevronRight />
                    </Button>
                </div>
            </div>

            <div className="min-h-0 flex-1 px-2 pb-2">
                <FullCalendar
                    ref={calendar}
                    plugins={[timeGridPlugin, interactionPlugin]}
                    initialView="timeGridWeek"
                    headerToolbar={false}
                    height="100%"
                    firstDay={1}
                    nowIndicator
                    expandRows
                    scrollTime="07:00:00"
                    slotDuration="00:30:00"
                    snapDuration="00:15:00"
                    slotLabelFormat={{ hour: "2-digit", minute: "2-digit", hour12: false }}
                    eventTimeFormat={{ hour: "2-digit", minute: "2-digit", hour12: false }}
                    dayHeaderFormat={{ weekday: "short", day: "numeric" }}
                    allDayText=""
                    editable
                    droppable
                    dragRevertDuration={0}
                    eventSources={sources}
                    // Tasks draw over feed events, so a work block is never
                    // hidden behind someone else's meeting.
                    eventOrder={(a, b) => Number(isFeed(a as FeedLike)) - Number(isFeed(b as FeedLike))}
                    datesSet={(info) => {
                        setRange({ from: wall(info.start), to: wall(info.end) });
                        setTitle(info.view.title);
                    }}
                    eventDidMount={(info) => {
                        const { feedColor, feedName, location } = info.event.extendedProps;
                        if (!feedColor) return;
                        info.el.style.setProperty("--feed", feedColor);
                        info.el.title = [info.event.title, location, feedName].filter(Boolean).join("\n");
                    }}
                    eventDrop={persistSpan}
                    eventResize={persistSpan}
                    // An inbox card dropped on the grid. The placeholder event
                    // FullCalendar adds is removed straight away; the real one
                    // arrives with the refetch, so there is one source of truth.
                    drop={(info) => {
                        const id = Number(info.draggedEl.dataset.taskId);
                        if (!id) return;
                        schedule.mutate({
                            id,
                            start: wall(info.date),
                            duration_min: Number(info.draggedEl.dataset.duration) || null,
                        });
                    }}
                    eventReceive={(info) => info.revert()}
                    // Dragging an event back over the inbox unschedules it.
                    eventDragStop={(info) => {
                        const id = info.event.extendedProps.taskId as number | undefined;
                        if (!id || !within(inboxRef.current, info.jsEvent)) return;
                        info.event.remove();
                        unschedule.mutate(id);
                    }}
                    eventClick={(info) => {
                        const id = info.event.extendedProps.taskId as number | undefined;
                        if (!id) return;
                        toggle.mutate({ id, done: Boolean(info.event.extendedProps.done) });
                    }}
                />
            </div>
        </section>
    );
}

function taskEvent(task: Task): EventInput {
    return {
        id: `task-${task.id}`,
        title: task.title,
        start: task.start ?? undefined,
        end: task.end ?? undefined,
        allDay: task.all_day,
        classNames: task.done ? ["planner-done"] : [],
        extendedProps: { taskId: task.id, done: task.done },
    };
}

/** Never carries a taskId, so no handler above can act on it. */
function feedEvent(event: FeedEvent): EventInput {
    return {
        id: event.id,
        title: event.title,
        start: event.start,
        end: event.end,
        allDay: event.all_day,
        editable: false,
        classNames: ["planner-feed"],
        extendedProps: { feedColor: event.color, feedName: event.feed_name, location: event.location },
    };
}

type FeedLike = { extendedProps?: Record<string, unknown> };

function isFeed(event: FeedLike): boolean {
    return Boolean(event.extendedProps?.feedColor);
}

function within(element: HTMLElement | null, point: MouseEvent): boolean {
    if (!element) return false;
    const box = element.getBoundingClientRect();
    return point.clientX >= box.left && point.clientX <= box.right && point.clientY >= box.top && point.clientY <= box.bottom;
}
