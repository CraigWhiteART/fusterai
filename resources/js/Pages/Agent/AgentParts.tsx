import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { GripVerticalIcon, HistoryIcon, PlusIcon, RotateCcwIcon, Trash2Icon } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import type { ListItem } from '@/types/agent';

// ── Navigation and page chrome ───────────────────────────────────────────────

export function PageHeader({
    title,
    description,
    actions,
    back,
}: {
    title: string;
    description?: React.ReactNode;
    /** The page's path; the sidebar shows where you are, so this is informational. */
    current: string;
    actions?: React.ReactNode;
    back?: { href: string; label: string };
}) {
    return (
        <div className="space-y-3">
            {back && (
                <Link href={back.href} className="text-xs text-muted-foreground hover:text-foreground">
                    ← {back.label}
                </Link>
            )}
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                    {description && <p className="text-sm text-muted-foreground mt-1 max-w-3xl">{description}</p>}
                </div>
                {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
            </div>
        </div>
    );
}

/** AppLayout + <Head> + the standard settings-page container and header. */
export function AgentPage({
    title,
    headTitle,
    description,
    current,
    actions,
    back,
    children,
    width = 'max-w-4xl',
}: {
    title: string;
    headTitle?: string;
    description?: React.ReactNode;
    current: string;
    actions?: React.ReactNode;
    back?: { href: string; label: string };
    children: React.ReactNode;
    width?: string;
}) {
    return (
        <AppLayout>
            <Head title={headTitle ?? title} />
            <div className={cn('w-full px-6 py-8 space-y-8', width)}>
                <PageHeader title={title} description={description} current={current} actions={actions} back={back} />
                {children}
            </div>
        </AppLayout>
    );
}

export function Section({
    title,
    description,
    children,
    className,
    actions,
}: {
    title?: React.ReactNode;
    description?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
    actions?: React.ReactNode;
}) {
    return (
        <section className={cn('rounded-xl border border-border bg-card p-5 space-y-4', className)}>
            {(title || actions) && (
                <div className="flex items-start justify-between gap-3">
                    <div className="space-y-1">
                        {title && <h2 className="text-sm font-semibold">{title}</h2>}
                        {description && <p className="text-xs text-muted-foreground">{description}</p>}
                    </div>
                    {actions}
                </div>
            )}
            {children}
        </section>
    );
}

export function EmptyState({
    icon,
    title,
    children,
    action,
}: {
    icon?: React.ReactNode;
    title: string;
    children?: React.ReactNode;
    action?: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-dashed border-border bg-card/50 px-6 py-10 text-center">
            {icon && <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-muted">{icon}</div>}
            <p className="text-sm font-semibold">{title}</p>
            {children && <div className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">{children}</div>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'warning' | 'danger'; children: React.ReactNode }) {
    return (
        <div
            className={cn(
                'rounded-xl border px-4 py-3 text-sm',
                tone === 'warning' && 'border-warning/30 bg-warning/10 text-foreground',
                tone === 'danger' && 'border-destructive/30 bg-destructive/10 text-foreground',
                tone === 'info' && 'border-info/30 bg-info/10 text-foreground',
            )}
        >
            {children}
        </div>
    );
}

/** Save button that says what it is doing. */
export function SaveButton({
    processing,
    children = 'Save',
    disabled,
}: {
    processing: boolean;
    children?: React.ReactNode;
    disabled?: boolean;
}) {
    return (
        <Button type="submit" disabled={processing || disabled}>
            {processing ? 'Saving…' : children}
        </Button>
    );
}

export function FieldError({ message }: { message?: string }) {
    return message ? <p className="text-xs text-destructive">{message}</p> : null;
}

/** First error for a field or any of its nested keys (e.g. `rules.2.then`). */
export function errorFor(errors: Record<string, string | undefined>, field: string): string | undefined {
    if (errors[field]) return errors[field];
    const nested = Object.keys(errors).find((key) => key.startsWith(`${field}.`));
    return nested ? errors[nested] : undefined;
}

export function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/** Edits a list of {id, text} items; ids are kept so items stay stable across versions. */
export function ItemListEditor({
    label,
    help,
    items,
    onChange,
    placeholder,
    error,
    addLabel = 'Add',
}: {
    label: string;
    help?: string;
    items: ListItem[];
    onChange: (items: ListItem[]) => void;
    placeholder?: string;
    error?: string;
    addLabel?: string;
}) {
    return (
        <div className="space-y-2">
            <div>
                <p className="text-sm font-medium">{label}</p>
                {help && <p className="text-xs text-muted-foreground">{help}</p>}
            </div>
            {items.length === 0 && <p className="text-xs text-muted-foreground italic">None yet.</p>}
            {items.map((item, index) => (
                <div key={item.id ?? `new-${index}`} className="flex items-center gap-2">
                    <GripVerticalIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground/40" />
                    <Input
                        value={item.text}
                        placeholder={placeholder}
                        aria-label={`${label} ${index + 1}`}
                        onChange={(e) => onChange(items.map((it, i) => (i === index ? { ...it, text: e.target.value } : it)))}
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 w-8 shrink-0 p-0 text-muted-foreground hover:text-destructive"
                        aria-label="Remove"
                        onClick={() => onChange(items.filter((_, i) => i !== index))}
                    >
                        <Trash2Icon className="h-3.5 w-3.5" />
                    </Button>
                </div>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={() => onChange([...items, { text: '' }])}>
                <PlusIcon className="mr-1 h-3.5 w-3.5" /> {addLabel}
            </Button>
            <FieldError message={error} />
        </div>
    );
}

// ── Version history with a simple before/after diff ──────────────────────────

export type VersionChange =
    | { field: string; kind: 'text'; before: string; after: string }
    | { field: string; kind: 'list'; added: string[]; removed: string[] };

/** Items present in one list and not the other (by exact text). */
export function diffList(before: string[], after: string[]): { added: string[]; removed: string[] } {
    const b = new Set(before.map((s) => s.trim()).filter(Boolean));
    const a = new Set(after.map((s) => s.trim()).filter(Boolean));
    return { added: [...a].filter((s) => !b.has(s)), removed: [...b].filter((s) => !a.has(s)) };
}

export function textChange(field: string, before: string | null | undefined, after: string | null | undefined): VersionChange[] {
    const b = (before ?? '').trim();
    const a = (after ?? '').trim();
    return a === b ? [] : [{ field, kind: 'text', before: b, after: a }];
}

export function listChange(field: string, before: string[], after: string[]): VersionChange[] {
    const { added, removed } = diffList(before, after);
    return added.length || removed.length ? [{ field, kind: 'list', added, removed }] : [];
}

export interface VersionLike {
    id: number;
    version: number;
    change_note: string | null;
    created_by: string | null;
    restored_from_id: number | null;
    created_at: string | null;
    is_live?: boolean;
}

function ChangeView({ change }: { change: VersionChange }) {
    if (change.kind === 'text') {
        return (
            <div className="space-y-1">
                <p className="text-xs font-medium">{change.field}</p>
                <div className="grid gap-1.5 sm:grid-cols-2">
                    <p className="whitespace-pre-wrap rounded-md border border-destructive/20 bg-destructive/5 px-2 py-1 text-xs">
                        <span className="mb-0.5 block text-[10px] font-semibold uppercase text-destructive/80">Before</span>
                        {change.before || <span className="italic text-muted-foreground">empty</span>}
                    </p>
                    <p className="whitespace-pre-wrap rounded-md border border-success/20 bg-success/5 px-2 py-1 text-xs">
                        <span className="mb-0.5 block text-[10px] font-semibold uppercase text-success">After</span>
                        {change.after || <span className="italic text-muted-foreground">empty</span>}
                    </p>
                </div>
            </div>
        );
    }
    return (
        <div className="space-y-1">
            <p className="text-xs font-medium">{change.field}</p>
            <ul className="space-y-0.5 text-xs">
                {change.removed.map((item) => (
                    <li
                        key={`-${item}`}
                        className="rounded bg-destructive/5 px-2 py-0.5 text-destructive line-through decoration-destructive/40"
                    >
                        − {item}
                    </li>
                ))}
                {change.added.map((item) => (
                    <li key={`+${item}`} className="rounded bg-success/5 px-2 py-0.5 text-success">
                        + {item}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Newest-first version list. Each entry shows who/when/why and what changed
 * against the version before it; restoring asks for confirmation first.
 */
export function VersionHistory<V extends VersionLike>({
    versions,
    describe,
    onRestore,
    restoring,
    noun = 'version',
}: {
    versions: V[];
    describe: (previous: V | null, current: V) => VersionChange[];
    onRestore: (version: V) => void;
    restoring?: number | null;
    noun?: string;
}) {
    if (versions.length === 0) {
        return <p className="text-sm text-muted-foreground">No versions yet. Saving creates version 1.</p>;
    }
    const byId = new Map(versions.map((v) => [v.id, v]));

    return (
        <ol className="space-y-2">
            {versions.map((version, index) => {
                const previous = versions[index + 1] ?? null;
                const changes = describe(previous, version);
                const restoredFrom = version.restored_from_id ? byId.get(version.restored_from_id) : null;
                return (
                    <li key={version.id}>
                        <details
                            className="group rounded-xl border border-border bg-card"
                            open={index === 0 && changes.length > 0 && changes.length <= 4}
                        >
                            <summary className="flex cursor-pointer list-none items-start justify-between gap-3 px-4 py-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        Version {version.version}
                                        {version.is_live && (
                                            <span className="ml-2 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary">
                                                Live
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDate(version.created_at)}
                                        {version.created_by ? ` · ${version.created_by}` : ' · system'}
                                        {restoredFrom ? ` · restored from version ${restoredFrom.version}` : ''}
                                    </p>
                                    {version.change_note && <p className="mt-0.5 text-xs">“{version.change_note}”</p>}
                                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                                        {previous === null
                                            ? 'First version'
                                            : changes.length === 0
                                              ? 'No content changes'
                                              : `Changed: ${changes.map((c) => c.field.toLowerCase()).join(', ')}`}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    {!version.is_live && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={restoring === version.id}
                                            onClick={(e) => {
                                                e.preventDefault();
                                                if (
                                                    window.confirm(
                                                        `Restore ${noun} ${version.version}? This saves a copy of it as a new version and makes it live straight away.`,
                                                    )
                                                ) {
                                                    onRestore(version);
                                                }
                                            }}
                                        >
                                            <RotateCcwIcon className="mr-1 h-3.5 w-3.5" />
                                            {restoring === version.id ? 'Restoring…' : 'Restore'}
                                        </Button>
                                    )}
                                    <HistoryIcon className="h-4 w-4 text-muted-foreground/50 transition-transform group-open:rotate-[-20deg]" />
                                </div>
                            </summary>
                            {changes.length > 0 && (
                                <div className="space-y-3 border-t border-border px-4 py-3">
                                    {changes.map((change, i) => (
                                        <ChangeView key={i} change={change} />
                                    ))}
                                </div>
                            )}
                        </details>
                    </li>
                );
            })}
        </ol>
    );
}
