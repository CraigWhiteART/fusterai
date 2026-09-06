import React, { useCallback, useEffect, useState } from 'react';
import { RefreshCwIcon, ShoppingBagIcon } from 'lucide-react';
import { csrfHeaders } from './csrf';

interface Generation {
    id: number;
    intent?: string | null;
    subtype?: string | null;
    confidence?: number | null;
    safe_to_send?: boolean;
    requires_human?: boolean;
    validator_passed?: boolean;
    unsupported_claims?: string[];
    sources?: {
        shopify?: string;
        knowledge?: string[];
        examples?: string[];
        intent?: string;
        subtype?: string;
    };
    status?: string;
}

interface ShopifyPayload {
    found?: boolean;
    reason?: string;
    order_number?: string | null;
    fulfilment_status?: string | null;
    payment_status?: string | null;
    tracking_number?: string | null;
    tracking_url?: string | null;
    shipping_country?: string | null;
    cancelled?: boolean;
    refunded?: boolean;
    products?: { title?: string; variant?: string; quantity?: number; preorder?: boolean | null }[];
}

interface CommerceAssistPayload {
    shopify?: ShopifyPayload | null;
    generation?: Generation | null;
}

interface Conversation {
    id: number;
}

interface Props {
    conversation?: Conversation;
    commerceAssist?: CommerceAssistPayload | null;
}

export default function ConversationPanel({ conversation, commerceAssist }: Props) {
    const [data, setData] = useState<CommerceAssistPayload>(commerceAssist ?? {});
    const [refreshing, setRefreshing] = useState(false);

    const load = useCallback(async () => {
        if (!conversation?.id) return;
        const res = await fetch(`/commerce-assist/conversations/${conversation.id}`, { headers: csrfHeaders() });
        if (!res.ok) return;
        setData(await res.json());
    }, [conversation?.id]);

    useEffect(() => {
        setData(commerceAssist ?? {});
    }, [commerceAssist]);

    useEffect(() => {
        const ch = window.Echo?.private(`conversation.${conversation?.id}`);
        ch?.listen('.ai.suggestion.ready', () => {
            void load();
        });
        return () => {
            ch?.stopListening('.ai.suggestion.ready');
        };
    }, [conversation?.id, load]);

    async function refreshShopify() {
        if (!conversation?.id) return;
        setRefreshing(true);
        try {
            const res = await fetch(`/commerce-assist/conversations/${conversation.id}/shopify-refresh`, {
                method: 'POST',
                headers: csrfHeaders(),
            });
            if (res.ok) {
                const json = await res.json();
                setData((prev) => ({ ...prev, shopify: json.shopify }));
            }
        } finally {
            setRefreshing(false);
        }
    }

    const shopify = data.shopify;
    const generation = data.generation;

    return (
        <div className="p-4 border-t border-border space-y-4">
            <div className="flex items-center justify-between">
                <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide flex items-center gap-1.5">
                    <ShoppingBagIcon className="h-3 w-3" /> Shopify
                </p>
                <button
                    type="button"
                    onClick={() => void refreshShopify()}
                    className="text-muted-foreground hover:text-foreground"
                    title="Refresh Shopify lookup"
                >
                    <RefreshCwIcon className={`h-3 w-3 ${refreshing ? 'animate-spin' : ''}`} />
                </button>
            </div>

            {shopify?.found ? (
                <div className="space-y-1 text-xs">
                    <p className="font-medium">
                        {shopify.order_number ?? 'Order'} — {shopify.fulfilment_status ?? 'unknown'}
                    </p>
                    <p className="text-muted-foreground">Payment: {shopify.payment_status ?? 'unknown'}</p>
                    {shopify.tracking_number && (
                        <p>
                            {shopify.tracking_url ? (
                                <a href={shopify.tracking_url} target="_blank" rel="noreferrer" className="text-primary hover:underline">
                                    {shopify.tracking_number}
                                </a>
                            ) : (
                                shopify.tracking_number
                            )}
                        </p>
                    )}
                    {shopify.shipping_country && <p className="text-muted-foreground">Ships to {shopify.shipping_country}</p>}
                    {(shopify.cancelled || shopify.refunded) && (
                        <p className="text-destructive">
                            {shopify.cancelled ? 'Cancelled' : ''}
                            {shopify.cancelled && shopify.refunded ? ' · ' : ''}
                            {shopify.refunded ? 'Refunded' : ''}
                        </p>
                    )}
                    {(shopify.products ?? []).slice(0, 4).map((product, i) => (
                        <p key={i} className="text-muted-foreground">
                            {product.quantity}× {product.title}
                            {product.variant ? ` (${product.variant})` : ''}
                            {product.preorder ? ' · preorder' : ''}
                        </p>
                    ))}
                </div>
            ) : (
                <p className="text-xs text-muted-foreground">{shopify?.reason ?? 'No Shopify match yet.'}</p>
            )}

            {generation && (
                <div className="space-y-1.5 pt-2 border-t border-border">
                    <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">AI used</p>
                    <p className="text-xs">
                        <span className="text-muted-foreground">Shopify: </span>
                        {generation.sources?.shopify ?? '—'}
                    </p>
                    <p className="text-xs">
                        <span className="text-muted-foreground">Knowledge: </span>
                        {(generation.sources?.knowledge ?? []).join(', ') || 'none'}
                    </p>
                    <p className="text-xs">
                        <span className="text-muted-foreground">Examples: </span>
                        {(generation.sources?.examples ?? []).join(', ') || 'none'}
                    </p>
                    <p className="text-xs">
                        Intent {generation.subtype || generation.intent || 'other'}
                        {generation.confidence != null ? ` · ${(generation.confidence * 100).toFixed(0)}%` : ''}
                    </p>
                    {generation.requires_human && (
                        <p className="text-[11px] text-warning bg-warning/10 rounded px-1.5 py-1">Human review required</p>
                    )}
                    {!generation.validator_passed && (
                        <div className="text-[11px] text-destructive bg-destructive/10 rounded px-1.5 py-1">
                            Unsupported claims:{' '}
                            {(generation.unsupported_claims ?? []).join(' · ') || 'flagged'}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
