import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, Outlet, useNavigate } from "@tanstack/react-router";
import { CalendarDays, CalendarRange, KeyRound, LogOut, RefreshCw } from "lucide-react";
import { useEffect, useRef } from "react";
import type { ReactNode } from "react";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { api, invalidatePlanner, queries } from "@/lib/api";
import { ago } from "@/lib/time";
import { cn } from "@/lib/utils";

/**
 * The signed-in frame: a quiet sidebar on the left, the page on the right.
 *
 * It also owns the one poll the app runs. Every 20 seconds it asks the API for
 * a revision fingerprint; when that changes, something moved (often on a phone,
 * over CalDAV) and every planner query is refetched.
 */
export function AppShell() {
    useRevisionWatcher();

    return (
        <div className="flex h-full">
            <Sidebar />
            <main className="min-w-0 flex-1 overflow-hidden">
                <Outlet />
            </main>
        </div>
    );
}

function useRevisionWatcher() {
    const client = useQueryClient();
    const { data: revision } = useQuery(queries.revision());
    const last = useRef<string | undefined>(undefined);

    useEffect(() => {
        if (revision === undefined) return;
        if (last.current !== undefined && last.current !== revision) invalidatePlanner(client);
        last.current = revision;
    }, [revision, client]);
}

function Sidebar() {
    const { data: me } = useQuery(queries.me());
    const { data: clients } = useQuery(queries.syncClients());
    const { data: feeds } = useQuery(queries.feeds());

    const lastClient = clients?.[0];
    const feedTrouble = feeds?.some((feed) => feed.enabled && !feed.healthy) ?? false;

    return (
        <aside className="hidden w-60 shrink-0 flex-col border-r border-sidebar-border bg-sidebar md:flex">
            <UserMenu name={me?.user.name ?? ""} email={me?.user.email ?? ""} />

            <nav className="flex flex-col gap-px px-2 py-1">
                <NavItem to="/" icon={<CalendarDays />}>
                    Planner
                </NavItem>
                <NavItem to="/calendars" icon={<CalendarRange />} alert={feedTrouble}>
                    Calendars
                </NavItem>
                <NavItem to="/sync" icon={<RefreshCw />}>
                    Sync
                </NavItem>
                <NavItem to="/tokens" icon={<KeyRound />}>
                    Access tokens
                </NavItem>
            </nav>

            {/* CalDAV clients poll on their own schedule, so "when did a device
                last check in" is the only honest answer to "is it syncing". */}
            <Link
                to="/sync"
                className="mt-auto flex items-center gap-2 px-4 py-3 text-xs text-muted-foreground hover:text-foreground"
            >
                <span
                    className={cn(
                        "size-1.5 rounded-full",
                        !lastClient && "bg-muted-foreground/40",
                        lastClient?.healthy && "bg-success",
                        lastClient && !lastClient.healthy && "bg-destructive",
                    )}
                />
                {lastClient ? `Synced ${ago(lastClient.last_seen_at)}` : "No device yet"}
            </Link>
        </aside>
    );
}

function NavItem({ to, icon, alert, children }: { to: string; icon: ReactNode; alert?: boolean; children: ReactNode }) {
    return (
        <Link
            to={to}
            className="flex h-8 items-center gap-2.5 rounded-md px-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground [&_svg]:size-4"
            activeProps={{ className: "bg-accent !text-foreground font-medium" }}
            activeOptions={{ exact: true }}
        >
            {icon}
            <span className="flex-1">{children}</span>
            {alert && <span className="size-1.5 rounded-full bg-destructive" aria-label="needs attention" />}
        </Link>
    );
}

function UserMenu({ name, email }: { name: string; email: string }) {
    const navigate = useNavigate();
    const client = useQueryClient();

    const signOut = async () => {
        await api.POST("/api/auth/logout");
        client.clear();
        navigate({ to: "/login" });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="m-2 flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm outline-none hover:bg-accent">
                <span className="flex size-5 items-center justify-center rounded bg-primary text-[0.625rem] font-semibold text-primary-foreground">
                    {(name || email).charAt(0).toUpperCase()}
                </span>
                <span className="truncate font-medium">{name || "Planner"}</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                <DropdownMenuLabel className="font-normal text-muted-foreground">{email}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={signOut}>
                    <LogOut />
                    Sign out
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** A Notion-style page header: big title, optional muted line under it. */
export function PageHeader({ title, description, children }: { title: string; description?: ReactNode; children?: ReactNode }) {
    return (
        <header className="mb-8 flex items-end justify-between gap-4">
            <div>
                <h1 className="text-3xl font-bold tracking-tight">{title}</h1>
                {description && <p className="mt-1.5 text-sm text-muted-foreground">{description}</p>}
            </div>
            {children}
        </header>
    );
}
