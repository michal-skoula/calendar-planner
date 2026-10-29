const pad = (n: number) => String(n).padStart(2, "0");

/**
 * A Date as naive wall clock, `2026-10-26T09:00:00`, with no zone.
 *
 * The API speaks wall clock in the planner's timezone, and FullCalendar runs in
 * the browser's local zone without converting, so the two agree with no
 * arithmetic on either side. The grid shows planner time whatever the laptop
 * is set to.
 */
export function wall(date: Date): string {
    return (
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
        `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
    );
}

/** Midnight today, as wall clock. */
export function startOfToday(): string {
    const now = new Date();
    return wall(new Date(now.getFullYear(), now.getMonth(), now.getDate()));
}

const relative = new Intl.RelativeTimeFormat("en", { numeric: "auto", style: "short" });

/** "3 min. ago", for an instant the API sends as UTC. */
export function ago(instant: string | null | undefined): string {
    if (!instant) return "never";

    const seconds = Math.round((new Date(instant).getTime() - Date.now()) / 1000);
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ["second", 60],
        ["minute", 60],
        ["hour", 24],
        ["day", 30],
        ["month", 12],
    ];

    let value = seconds;
    for (const [unit, size] of steps) {
        if (Math.abs(value) < size) return relative.format(value, unit);
        value = Math.round(value / size);
    }

    return relative.format(value, "year");
}

/** "14:05" in the browser's zone, for activity rows. */
export function clock(instant: string): string {
    return new Date(instant).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: false });
}

/** "27 Oct" from a `YYYY-MM-DD` date. */
export function shortDate(date: string): string {
    const [y, m, d] = date.split("-").map(Number);
    return new Date(y, m - 1, d).toLocaleDateString([], { day: "numeric", month: "short" });
}

export function isPast(date: string): boolean {
    return date < startOfToday().slice(0, 10);
}
