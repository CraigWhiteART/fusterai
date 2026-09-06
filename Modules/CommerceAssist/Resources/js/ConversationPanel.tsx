import React, { useCallback, useEffect, useState } from 'react';
import { AlertTriangleIcon, PackageIcon, RefreshCwIcon, ShoppingBagIcon } from 'lucide-react';
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
        tracking?: string;
        facts?: string[];
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

interface TrackingEvent {
    occurred_at?: string | null;
    description?: string | null;
    location?: string | null;
    last_mile?: boolean;
}

interface TrackingPayload {
    found?: boolean;
    reason?: string | null;
    provider?: string;
    status?: string;
    status_label?: string | null;
    sub_status?: string | null;
    carrier_name?: string | null;
    tracking_number?: string | null;
    tracking_url?: string | null;
    last_event?: string | null;
    last_event_at?: string | null;
    days_since_last_scan?: number | null;
    delivered_at?: string | null;
    estimated_delivery_at?: string | null;
    needs_attention?: boolean;
    last_mile_handoff?: boolean;
    last_mile_carrier?: string | null;
    last_mile_tracking_number?: string | null;
    last_mile_tracking_url?: string | null;
    proof_of_delivery?: {
        type?: string;
        label?: string;
        detail?: string | null;
        location?: string | null;
        attributable?: boolean;
    } | null;
    events?: TrackingEvent[];
    fetched_at?: string | null;
    error?: string | null;
}

interface LiveFact {
    id: number;
    title?: string | null;
    body: string;
}

interface CommerceAssistPayload {
    shopify?: ShopifyPayload | null;
    tracking?: TrackingPayload | null;
    generation?: Generation | null;
    liveFacts?: LiveFact[];
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
    const [refreshingTracking, setRefreshingTracking] = useState(false);

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

    async function refreshTracking() {
        if (!conversation?.id) return;
        setRefreshingTracking(true);
        try {
            const res = await fetch(`/commerce-assist/conversations/${conversation.id}/tracking-refresh`, {
                method: 'POST',
                headers: csrfHeaders(),
            });
            if (res.ok) {
                const json = await res.json();
                setData((prev) => ({ ...prev, tracking: json.tracking }));
            }
        } finally {
            setRefreshingTracking(false);
        }
    }

    const shopify = data.shopify;
    const tracking = data.tracking;
    const generation = data.generation;
    const liveFacts = data.liveFacts ?? [];

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

            {tracking && (
                <div className="space-y-1.5 pt-2 border-t border-border">
                    <div className="flex items-center justify-between">
                        <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide flex items-center gap-1.5">
                            <PackageIcon className="h-3 w-3" /> Carrier
                        </p>
                        <button
                            type="button"
                            onClick={() => void refreshTracking()}
                            className="text-muted-foreground hover:text-foreground"
                            title="Refresh carrier tracking"
                        >
                            <RefreshCwIcon className={`h-3 w-3 ${refreshingTracking ? 'animate-spin' : ''}`} />
                        </button>
                    </div>

                    {tracking.found ? (
                        <div className="space-y-1 text-xs">
                            <p className="font-medium">
                                {tracking.status_label ?? 'Unknown'}
                                {tracking.carrier_name ? ` · ${tracking.carrier_name}` : ''}
                            </p>
                            {tracking.last_event && (
                                <p className="text-muted-foreground">
                                    {tracking.last_event}
                                    {tracking.days_since_last_scan != null ? ` · ${tracking.days_since_last_scan}d ago` : ''}
                                </p>
                            )}
                            {tracking.estimated_delivery_at && (
                                <p className="text-muted-foreground">Est. delivery {tracking.estimated_delivery_at}</p>
                            )}
                            {tracking.last_mile_handoff && (
                                <p>
                                    <span className="text-muted-foreground">Last mile: </span>
                                    {tracking.last_mile_carrier ?? 'domestic carrier'}
                                    {tracking.last_mile_tracking_number ? ` · ${tracking.last_mile_tracking_number}` : ''}
                                </p>
                            )}
                            {tracking.proof_of_delivery && (
                                <p className={tracking.proof_of_delivery.attributable ? '' : 'text-warning'}>
                                    <span className="text-muted-foreground">Proof: </span>
                                    {tracking.proof_of_delivery.label}
                                    {tracking.proof_of_delivery.detail ? ` — ${tracking.proof_of_delivery.detail}` : ''}
                                </p>
                            )}
                            {tracking.needs_attention && (
                                <p className="text-[11px] text-destructive bg-destructive/10 rounded px-1.5 py-1 flex items-center gap-1">
                                    <AlertTriangleIcon className="h-3 w-3 shrink-0" />
                                    Carrier flagged this shipment
                                </p>
                            )}
                            {(tracking.events ?? []).slice(0, 3).map((event, i) => (
                                <p key={i} className="text-[11px] text-muted-foreground">
                                    {event.occurred_at?.slice(0, 10) ?? '—'} {event.description}
                                    {event.location ? ` (${event.location})` : ''}
                                    {event.last_mile ? ' · last mile' : ''}
                                </p>
                            ))}
                        </div>
                    ) : (
                        <p className="text-xs text-muted-foreground">{tracking.error ?? tracking.reason ?? 'No carrier record yet.'}</p>
                    )}
                </div>
            )}

            {liveFacts.length > 0 && (
                <div className="space-y-1.5 pt-2 border-t border-border">
                    <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Current facts</p>
                    {liveFacts.map((fact) => (
                        <p key={fact.id} className="text-xs leading-relaxed">
                            {fact.body}
                        </p>
                    ))}
                </div>
            )}

            {generation && (
                <div className="space-y-1.5 pt-2 border-t border-border">
                    <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">AI used</p>
                    <p className="text-xs">
                        <span className="text-muted-foreground">Shopify: </span>
                        {generation.sources?.shopify ?? '—'}
                    </p>
                    <p className="text-xs">
                        <span className="text-muted-foreground">Carrier: </span>
                        {generation.sources?.tracking ?? '—'}
                    </p>
                    <p className="text-xs">
                        <span className="text-muted-foreground">Facts: </span>
                        {(generation.sources?.facts ?? []).join(', ') || 'none'}
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
                            Unsupported claims: {(generation.unsupported_claims ?? []).join(' · ') || 'flagged'}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
