import { useQuery } from "@tanstack/react-query";
import { useRef } from "react";
import { Inbox } from "@/components/inbox";
import { WeekCalendar } from "@/components/week-calendar";
import { queries } from "@/lib/api";

export function PlannerPage() {
    const inbox = useRef<HTMLDivElement>(null);
    const { data: me } = useQuery(queries.me());

    return (
        <div className="flex h-full">
            <Inbox ref={inbox} />
            <WeekCalendar inboxRef={inbox} defaultDuration={me?.default_duration_min ?? 30} />
        </div>
    );
}
