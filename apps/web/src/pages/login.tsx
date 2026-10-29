import { useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import { useState } from "react";
import type { FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api, ApiError, queries } from "@/lib/api";

/**
 * One account, the same email and password Apple Calendar uses over CalDAV.
 */
export function LoginPage() {
    const navigate = useNavigate();
    const client = useQueryClient();
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);

        setBusy(true);
        setError(null);

        try {
            await api.POST("/api/auth/login", {
                body: { email: String(form.get("email")), password: String(form.get("password")) },
            });
            await client.invalidateQueries({ queryKey: queries.me().queryKey });
            navigate({ to: "/" });
        } catch (e) {
            setError(e instanceof ApiError ? (e.field("email") ?? e.message) : "Something went wrong.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex min-h-full items-center justify-center px-4">
            <form onSubmit={submit} className="w-full max-w-xs space-y-6">
                <div className="space-y-1">
                    <h1 className="text-2xl font-bold tracking-tight">Planner</h1>
                    <p className="text-sm text-muted-foreground">Sign in to plan your week.</p>
                </div>

                <div className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="email">Email</Label>
                        <Input id="email" name="email" type="email" autoComplete="username" required autoFocus />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="password">Password</Label>
                        <Input id="password" name="password" type="password" autoComplete="current-password" required />
                    </div>
                    {error && <p className="text-sm text-destructive">{error}</p>}
                </div>

                <Button type="submit" className="w-full" disabled={busy}>
                    {busy ? "Signing in…" : "Continue"}
                </Button>
            </form>
        </div>
    );
}
