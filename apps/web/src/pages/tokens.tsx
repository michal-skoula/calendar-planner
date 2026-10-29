import type { AccessToken } from "@planner/api-client";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Copy, KeyRound } from "lucide-react";
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
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { accessTokens, ApiError, queries } from "@/lib/api";
import { ago } from "@/lib/time";
import { cn } from "@/lib/utils";

/**
 * Bearer tokens for everything that is not this web app: the iOS Shortcut,
 * MCP clients, scripts. Each has the same access as the account.
 */
export function TokensPage() {
    const client = useQueryClient();
    const { data: list = [], isPending } = useQuery(queries.tokens());
    const [created, setCreated] = useState<{ name: string; token: string } | null>(null);
    const [revoking, setRevoking] = useState<AccessToken | null>(null);

    const revoke = useMutation({
        mutationFn: (token: AccessToken) => accessTokens.revoke(token.id),
        onSuccess: (_, token) => toast.success(`Revoked ${token.name}.`),
        onError: (error) => toast.error(error.message),
        onSettled: () => client.invalidateQueries({ queryKey: ["tokens"] }),
    });

    return (
        <div className="h-full overflow-y-auto">
            <div className="mx-auto max-w-3xl px-8 py-14">
                <PageHeader
                    title="Access tokens"
                    description="For the iOS Shortcut, MCP clients and scripts. A token can do anything you can, so give each client its own and revoke the ones you stop using."
                />

                <CreateToken onCreated={setCreated} />

                <div className="mt-10 divide-y border-t">
                    {list.map((token) => (
                        <TokenRow key={token.id} token={token} onRevoke={() => setRevoking(token)} />
                    ))}
                    {!isPending && list.length === 0 && <p className="py-6 text-sm text-muted-foreground">No tokens yet.</p>}
                </div>
            </div>

            <NewTokenDialog created={created} onClose={() => setCreated(null)} />

            <AlertDialog open={revoking !== null} onOpenChange={(open) => !open && setRevoking(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Revoke “{revoking?.name}”?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Anything using this token is signed out on its next request. This cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => revoking && revoke.mutate(revoking)}>Revoke</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

const PRESETS = [
    { value: "30", label: "30 days" },
    { value: "90", label: "90 days" },
    { value: "365", label: "1 year" },
    { value: "never", label: "Never" },
    { value: "custom", label: "Custom date" },
] as const;

function CreateToken({ onCreated }: { onCreated: (created: { name: string; token: string }) => void }) {
    const client = useQueryClient();
    const [expiry, setExpiry] = useState<string>("90");
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});

    const create = useMutation({
        mutationFn: accessTokens.create,
        onSettled: () => client.invalidateQueries({ queryKey: ["tokens"] }),
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const formEl = event.currentTarget;
        const form = new FormData(formEl);
        const name = String(form.get("name")).trim();

        create.mutate(
            { name, expires_on: expiresOn(expiry, String(form.get("date") ?? "")) },
            {
                onSuccess: (data) => {
                    setErrors({});
                    formEl.reset();
                    setExpiry("90");
                    onCreated({ name, token: data.token });
                },
                onError: (e) => {
                    if (e instanceof ApiError && e.status === 422) setErrors({ name: e.field("name"), expires_on: e.field("expires_on") });
                    else toast.error(e.message);
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
            <div className="min-w-48 flex-1 space-y-1.5">
                <Label htmlFor="token-name">Name</Label>
                <Input id="token-name" name="name" required maxLength={100} placeholder="iOS Shortcut" autoComplete="off" />
                {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="token-expiry">Expires</Label>
                <div className="flex gap-2">
                    <select
                        id="token-expiry"
                        value={expiry}
                        onChange={(e) => setExpiry(e.target.value)}
                        className="h-9 rounded-md border border-input bg-transparent px-2.5 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {PRESETS.map((preset) => (
                            <option key={preset.value} value={preset.value}>
                                {preset.label}
                            </option>
                        ))}
                    </select>
                    {expiry === "custom" && (
                        <Input name="date" type="date" required min={isoDate(new Date())} className="w-40" aria-label="Expiry date" />
                    )}
                </div>
                {errors.expires_on && <p className="text-xs text-destructive">{errors.expires_on}</p>}
            </div>

            <Button type="submit" disabled={create.isPending}>
                {create.isPending ? "Creating…" : "Create token"}
            </Button>
        </form>
    );
}

/** The secret, shown once. An alert dialog so a stray click outside cannot lose it. */
function NewTokenDialog({ created, onClose }: { created: { name: string; token: string } | null; onClose: () => void }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (!created) return;
        await navigator.clipboard.writeText(created.token);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <AlertDialog open={created !== null} onOpenChange={(open) => !open && onClose()}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>“{created?.name}” is ready</AlertDialogTitle>
                    <AlertDialogDescription>
                        Copy it now. Only a hash is stored, so this is the one time it can be shown. Send it as{" "}
                        <code className="font-mono text-xs">Authorization: Bearer …</code>
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="flex items-center gap-2 rounded-md border bg-muted px-3 py-2">
                    <code className="min-w-0 flex-1 font-mono text-xs break-all select-all">{created?.token}</code>
                    <Button variant="ghost" size="icon" className="size-7 shrink-0" onClick={copy} aria-label="Copy token">
                        {copied ? <Check /> : <Copy />}
                    </Button>
                </div>

                <AlertDialogFooter>
                    <AlertDialogAction onClick={onClose}>Done</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function TokenRow({ token, onRevoke }: { token: AccessToken; onRevoke: () => void }) {
    return (
        <div className={cn("flex items-center gap-3 py-3", token.expired && "opacity-60")}>
            <KeyRound className="size-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm">{token.name}</p>
                <p className="text-xs text-muted-foreground">
                    Created {ago(token.created_at)} · {token.last_used_at ? `last used ${ago(token.last_used_at)}` : "never used"} ·{" "}
                    <Expiry token={token} />
                </p>
            </div>
            <Button variant="ghost" size="sm" className="text-muted-foreground" onClick={onRevoke}>
                Revoke
            </Button>
        </div>
    );
}

function Expiry({ token }: { token: AccessToken }) {
    if (!token.expires_at) return <span>never expires</span>;

    const date = new Date(token.expires_at).toLocaleDateString([], { day: "numeric", month: "short", year: "numeric" });

    return token.expired ? <span className="text-destructive">expired {date}</span> : <span>expires {date}</span>;
}

/** The API takes the last valid day; presets count forward from today. */
function expiresOn(preset: string, custom: string): string | null {
    if (preset === "never") return null;
    if (preset === "custom") return custom || null;

    const date = new Date();
    date.setDate(date.getDate() + Number(preset));
    return isoDate(date);
}

function isoDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
