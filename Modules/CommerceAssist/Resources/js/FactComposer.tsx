import React, { useEffect, useMemo, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { csrfHeaders } from './csrf';

interface Conversation {
    id: number;
}

interface ComposerDefaults {
    intents?: string[];
    products?: string[];
}

interface DraftMatch {
    id: number;
    subject: string;
    customer?: string | null;
    intent?: string | null;
}

interface Props {
    conversation?: Conversation;
    commerceAssist?: {
        composer?: ComposerDefaults;
        generation?: { id: number } | null;
        liveFacts?: { id: number; body: string }[];
    } | null;
}

function Chip({ label, onRemove }: { label: string; onRemove: () => void }) {
    return (
        <button
            type="button"
            onClick={onRemove}
            className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] hover:bg-muted/80"
            title="Remove"
        >
            {label}
            <span className="text-muted-foreground">×</span>
        </button>
    );
}

export default function FactComposer({ conversation, commerceAssist }: Props) {
    const [open, setOpen] = useState(false);
    const [body, setBody] = useState('');
    const [products, setProducts] = useState<string[]>(commerceAssist?.composer?.products ?? []);
    const [intents, setIntents] = useState<string[]>(commerceAssist?.composer?.intents ?? []);
    const [productInput, setProductInput] = useState('');
    const [drafts, setDrafts] = useState<DraftMatch[]>([]);
    const [saving, setSaving] = useState(false);
    const [done, setDone] = useState<string | null>(null);

    useEffect(() => {
        setProducts(commerceAssist?.composer?.products ?? []);
        setIntents(commerceAssist?.composer?.intents ?? []);
    }, [commerceAssist?.composer]);

    const payload = useMemo(
        () => ({
            conversation_id: conversation?.id,
            intent_slugs: intents,
            product_keywords: products,
        }),
        [conversation?.id, intents, products],
    );

    useEffect(() => {
        if (!open || !conversation?.id) return;
        const timer = setTimeout(() => {
            void fetch('/commerce-assist/facts/preview', {
                method: 'POST',
                headers: csrfHeaders(true),
                body: JSON.stringify(payload),
            })
                .then((res) => (res.ok ? res.json() : null))
                .then((json) => {
                    if (json?.drafts) setDrafts(json.drafts);
                });
        }, 250);
        return () => clearTimeout(timer);
    }, [open, payload, conversation?.id]);

    if (!conversation?.id) return null;

    function addProduct(event: React.FormEvent) {
        event.preventDefault();
        const value = productInput.trim();
        if (!value || products.includes(value)) return;
        setProducts((prev) => [...prev, value]);
        setProductInput('');
    }

    async function save() {
        if (!body.trim()) return;
        setSaving(true);
        try {
            const res = await fetch('/commerce-assist/facts', {
                method: 'POST',
                headers: csrfHeaders(true),
                body: JSON.stringify({
                    ...payload,
                    body: body.trim(),
                }),
            });
            const json = await res.json();
            if (!res.ok) return;
            const count = json.queued ?? drafts.length;
            setDone(
                count <= 1
                    ? 'This draft will rewrite with the new fact.'
                    : `${count} related unsent drafts are rewriting now. This one will refresh in a moment.`,
            );
            setBody('');
            setOpen(false);
        } finally {
            setSaving(false);
        }
    }

    if (done) {
        return <div className="mb-3 rounded-lg border border-success/30 bg-success/10 px-3 py-2 text-xs">{done}</div>;
    }

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="mb-3 text-left text-xs text-muted-foreground hover:text-foreground"
            >
                {commerceAssist?.generation
                    ? 'Draft using current facts. Stock, dates or timelines changed? Update once and rewrite related emails.'
                    : 'Need to tell the AI something new — a date, stock, a timeline? Update related drafts in one go.'}
            </button>
        );
    }

    return (
        <div className="mb-3 rounded-xl border border-border bg-card p-3 space-y-3">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-medium">What’s true now?</p>
                    <p className="text-xs text-muted-foreground">Related unsent drafts will be rewritten. Nothing is sent to customers.</p>
                </div>
                <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={() => setOpen(false)}>
                    Cancel
                </button>
            </div>
            <textarea
                className="w-full min-h-[72px] rounded-lg border border-input bg-background px-3 py-2 text-sm"
                placeholder="e.g. Cabinet preorders now ship the week of 21 April. Don’t quote March."
                value={body}
                onChange={(e) => setBody(e.target.value)}
                autoFocus
            />
            <div className="space-y-1.5">
                <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wide">Applies to products</p>
                <div className="flex flex-wrap gap-1.5">
                    {products.map((product) => (
                        <Chip
                            key={product}
                            label={product}
                            onRemove={() => setProducts((prev) => prev.filter((item) => item !== product))}
                        />
                    ))}
                    <form onSubmit={addProduct} className="inline">
                        <input
                            className="h-6 w-36 rounded-md border border-input bg-background px-2 text-[11px]"
                            placeholder="Add product / SKU"
                            value={productInput}
                            onChange={(e) => setProductInput(e.target.value)}
                        />
                    </form>
                </div>
                {products.length > 0 ? (
                    <p className="text-[11px] text-muted-foreground">
                        Remove products to widen this to every unsent draft with the same intent.
                    </p>
                ) : (
                    <p className="text-[11px] text-muted-foreground">No product filter — matching by intent only.</p>
                )}
            </div>
            {intents.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {intents.map((intent) => (
                        <Chip key={intent} label={intent} onRemove={() => setIntents((prev) => prev.filter((item) => item !== intent))} />
                    ))}
                </div>
            )}
            <div className="rounded-lg bg-muted/40 px-3 py-2 space-y-1">
                <p className="text-xs font-medium">
                    {drafts.length <= 1
                        ? 'Only this draft will be rewritten.'
                        : `This will rewrite ${drafts.length} related unsent drafts.`}
                </p>
                {drafts.slice(0, 5).map((draft) => (
                    <p key={draft.id} className="text-[11px] text-muted-foreground truncate">
                        {draft.subject}
                        {draft.customer ? ` · ${draft.customer}` : ''}
                    </p>
                ))}
                {drafts.length > 5 && <p className="text-[11px] text-muted-foreground">+{drafts.length - 5} more</p>}
            </div>
            <Button type="button" size="sm" className="h-8" disabled={saving || !body.trim()} onClick={() => void save()}>
                {saving ? 'Rewriting…' : drafts.length > 1 ? `Save & rewrite ${drafts.length} drafts` : 'Save & rewrite this draft'}
            </Button>
        </div>
    );
}
