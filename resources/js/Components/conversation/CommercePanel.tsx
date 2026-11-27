import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { AlertTriangleIcon, ExternalLinkIcon, PackageIcon, RefreshCwIcon, ShoppingBagIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

interface ShopifyOrder {
    found: boolean;
    has_order: boolean;
    reason: string | null;
    matched_by: 'order_number' | 'email' | null;
    order_email_matches: boolean | null;
    order_email: string | null;
    customer_name: string | null;
    /** Shopify admin pages; null when the shop domain or the id is unknown. */
    customer_admin_url: string | null;
    order_number: string | null;
    order_admin_url: string | null;
    order_date: string | null;
    order_age_days: number | null;
    payment_status: string | null;
    fulfilment_status: string | null;
    tracking_number: string | null;
    tracking_url: string | null;
    tracking_company: string | null;
    shipping_country: string | null;
    cancelled: boolean;
    refunded: boolean;
    products: { title: string | null; variant: string | null; quantity: number | null; preorder: boolean | null }[];
    fetched_at: string | null;
    error: string | null;
}

interface Tracking {
    found?: boolean;
    reason?: string | null;
    status?: string;
    status_label?: string | null;
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
    proof_of_delivery?: { label?: string; detail?: string | null; attributable?: boolean } | null;
    events?: { occurred_at?: string | null; description?: string | null; location?: string | null }[];
    stalled?: boolean;
    fetched_at?: string | null;
    error?: string | null;
}

interface CommerceState {
    configured: { shopify: boolean; tracking: boolean };
    shopify: ShopifyOrder | null;
    tracking: Tracking | null;
    stale_days: number;
}

function humanise(value: string | null | undefined): string {
    if (!value) return 'unknown';
    return value.replace(/_/g, ' ').toLowerCase();
}

function ago(iso: string | null | undefined): string | null {
    if (!iso) return null;
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 48) return `${hours} h ago`;
    return `${Math.round(hours / 24)} days ago`;
}

function RefreshButton({ busy, label, onClick }: { busy: boolean; label: string; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={busy}
            className="inline-flex items-center gap-1 rounded px-1 py-0.5 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-60"
            title={label}
            aria-label={label}
        >
            <RefreshCwIcon className={cn('h-3 w-3', busy && 'animate-spin')} />
            {busy ? 'Checking…' : 'Refresh'}
        </button>
    );
}

/** Opens a Shopify admin page in a new tab. */
function AdminLink({ href, title, children }: { href: string; title?: string; children: ReactNode }) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            title={title}
            className="inline-flex max-w-full items-center gap-1 text-primary hover:underline"
        >
            <span className="truncate">{children}</span>
            <ExternalLinkIcon className="h-3 w-3 shrink-0 opacity-70" />
        </a>
    );
}

const heading = 'text-[10px] font-semibold uppercase tracking-[0.1em] text-muted-foreground/70 flex items-center gap-1.5';

/**
 * Shopify order + carrier tracking for the open conversation, from
 * GET /commerce/conversations/{id}. Opening never calls Shopify; the refresh
 * buttons do. Renders nothing when Shopify is not connected (or on any error).
 */
export default function CommercePanel({ conversationId, refreshKey = 0 }: { conversationId: number; refreshKey?: number }) {
    const [state, setState] = useState<CommerceState | null>(null);
    const [busy, setBusy] = useState<'shopify' | 'tracking' | null>(null);
    const [failed, setFailed] = useState<string | null>(null);

    useEffect(() => {
        let alive = true;
        window.axios
            .get<CommerceState>(`/commerce/conversations/${conversationId}`)
            .then(({ data }) => alive && setState(data))
            .catch(() => alive && setState(null));
        return () => {
            alive = false;
        };
        // refreshKey: re-read after the agent ran (it looks the order up itself).
    }, [conversationId, refreshKey]);

    const refresh = useCallback(
        async (what: 'shopify' | 'tracking') => {
            setBusy(what);
            setFailed(null);
            try {
                const { data } = await window.axios.post<CommerceState>(`/commerce/conversations/${conversationId}/refresh-${what}`);
                setState(data);
            } catch (e: unknown) {
                const status = (e as { response?: { status?: number } }).response?.status;
                setFailed(
                    status === 429
                        ? 'Too many refreshes; wait a minute.'
                        : status === 403
                          ? 'You cannot refresh this conversation.'
                          : 'Refresh failed.',
                );
            } finally {
                setBusy(null);
            }
        },
        [conversationId],
    );

    if (!state?.configured?.shopify) return null;

    const order = state.shopify;
    const tracking = state.tracking;

    return (
        <div className="space-y-4 border-t border-border p-4 text-xs">
            <div className="space-y-1.5">
                <div className="flex items-center justify-between">
                    <p className={heading}>
                        <ShoppingBagIcon className="h-3 w-3" /> Shopify order
                    </p>
                    <RefreshButton
                        busy={busy === 'shopify'}
                        label="Look up the order in Shopify again"
                        onClick={() => void refresh('shopify')}
                    />
                </div>

                {!order ? (
                    <p className="text-muted-foreground">Not looked up yet. Refresh to search Shopify for this customer.</p>
                ) : order.error && !order.found ? (
                    <p className="text-destructive">{order.reason ?? 'Shopify lookup failed.'}</p>
                ) : !order.has_order ? (
                    <div className="space-y-1">
                        <p className="text-muted-foreground">
                            <span className="font-medium text-foreground">No order found for this customer.</span>{' '}
                            {order.reason ?? 'Shopify has no order for this email or any order number in the mail.'}
                        </p>
                        {order.customer_admin_url && (
                            <AdminLink href={order.customer_admin_url}>{order.customer_name ?? 'Customer'} in Shopify</AdminLink>
                        )}
                    </div>
                ) : (
                    <div className="space-y-1">
                        <p className="text-sm font-semibold">
                            {order.order_admin_url ? (
                                <AdminLink href={order.order_admin_url} title="Open this order in Shopify">
                                    {order.order_number}
                                </AdminLink>
                            ) : (
                                order.order_number
                            )}
                        </p>
                        <p className="text-muted-foreground">
                            {order.matched_by === 'order_number'
                                ? 'Matched by the order number in the email'
                                : order.matched_by === 'email'
                                  ? "Matched by the customer's email"
                                  : 'Matched by Shopify search'}
                            {order.order_date ? ` · placed ${new Date(order.order_date).toLocaleDateString()}` : ''}
                        </p>
                        {order.order_email_matches === false && (
                            <p className="flex items-start gap-1 rounded bg-warning/10 px-1.5 py-1 text-[11px] text-warning">
                                <AlertTriangleIcon className="mt-px h-3 w-3 shrink-0" />
                                The order email ({order.order_email}) is not the sender, so the agent will not share its details.
                            </p>
                        )}
                        <dl className="grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5">
                            {order.customer_admin_url && (
                                <>
                                    <dt className="text-muted-foreground">Customer</dt>
                                    <dd className="min-w-0 truncate">
                                        <AdminLink href={order.customer_admin_url} title="Open this customer in Shopify">
                                            {order.customer_name ?? 'View in Shopify'}
                                        </AdminLink>
                                    </dd>
                                </>
                            )}
                            <dt className="text-muted-foreground">Fulfilment</dt>
                            <dd className="capitalize">{humanise(order.fulfilment_status)}</dd>
                            <dt className="text-muted-foreground">Payment</dt>
                            <dd className="capitalize">{humanise(order.payment_status)}</dd>
                            {order.shipping_country && (
                                <>
                                    <dt className="text-muted-foreground">Ships to</dt>
                                    <dd>{order.shipping_country}</dd>
                                </>
                            )}
                            {order.tracking_number && (
                                <>
                                    <dt className="text-muted-foreground">Tracking</dt>
                                    <dd className="truncate">
                                        {order.tracking_url ? (
                                            <a
                                                href={order.tracking_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-primary hover:underline"
                                            >
                                                {order.tracking_number}
                                            </a>
                                        ) : (
                                            order.tracking_number
                                        )}
                                        {order.tracking_company ? ` · ${order.tracking_company}` : ''}
                                    </dd>
                                </>
                            )}
                        </dl>
                        {(order.cancelled || order.refunded) && (
                            <p className="font-medium text-destructive">
                                {[order.cancelled && 'Cancelled', order.refunded && 'Refunded'].filter(Boolean).join(' · ')}
                            </p>
                        )}
                        {order.products.slice(0, 4).map((product, i) => (
                            <p key={i} className="text-muted-foreground">
                                {product.quantity ?? 1}× {product.title}
                                {product.variant ? ` (${product.variant})` : ''}
                                {product.preorder ? ' · pre-order' : ''}
                            </p>
                        ))}
                        {order.products.length > 4 && <p className="text-muted-foreground">+{order.products.length - 4} more</p>}
                    </div>
                )}
                {order?.fetched_at && <p className="text-[10px] text-muted-foreground/70">Checked {ago(order.fetched_at)}</p>}
            </div>

            {state.configured.tracking && order?.has_order && (
                <div className="space-y-1.5 border-t border-border pt-3">
                    <div className="flex items-center justify-between">
                        <p className={heading}>
                            <PackageIcon className="h-3 w-3" /> Carrier
                        </p>
                        <RefreshButton
                            busy={busy === 'tracking'}
                            label="Ask the carrier for the latest scan"
                            onClick={() => void refresh('tracking')}
                        />
                    </div>
                    {!tracking ? (
                        <p className="text-muted-foreground">
                            {order.tracking_number
                                ? 'Not checked yet. Refresh to ask the carrier.'
                                : 'No tracking number on this order yet.'}
                        </p>
                    ) : !tracking.found ? (
                        <p className="text-muted-foreground">
                            {tracking.error ? 'Carrier lookup failed.' : (tracking.reason ?? 'The carrier has no record yet.')}
                        </p>
                    ) : (
                        <div className="space-y-1">
                            <p className="font-medium">
                                {tracking.status_label ?? humanise(tracking.status)}
                                {tracking.carrier_name ? ` · ${tracking.carrier_name}` : ''}
                            </p>
                            {tracking.last_event && (
                                <p className="text-muted-foreground">
                                    Last scan: {tracking.last_event}
                                    {tracking.days_since_last_scan != null &&
                                        ` (${tracking.days_since_last_scan === 0 ? 'today' : `${tracking.days_since_last_scan} day${tracking.days_since_last_scan === 1 ? '' : 's'} ago`})`}
                                </p>
                            )}
                            {tracking.stalled && (
                                <p className="flex items-center gap-1 rounded bg-warning/10 px-1.5 py-1 text-[11px] text-warning">
                                    <AlertTriangleIcon className="h-3 w-3 shrink-0" />
                                    No scan for {state.stale_days}+ days
                                </p>
                            )}
                            {tracking.needs_attention && (
                                <p className="flex items-center gap-1 rounded bg-destructive/10 px-1.5 py-1 text-[11px] text-destructive">
                                    <AlertTriangleIcon className="h-3 w-3 shrink-0" />
                                    The carrier flagged this shipment
                                </p>
                            )}
                            {tracking.estimated_delivery_at && (
                                <p className="text-muted-foreground">
                                    Expected {new Date(tracking.estimated_delivery_at).toLocaleDateString()}
                                </p>
                            )}
                            {tracking.last_mile_handoff && (
                                <p>
                                    <span className="text-muted-foreground">Last mile: </span>
                                    {tracking.last_mile_carrier ?? 'local carrier'}
                                    {tracking.last_mile_tracking_number ? ` · ${tracking.last_mile_tracking_number}` : ''}
                                </p>
                            )}
                            {tracking.proof_of_delivery?.label && (
                                <p className={tracking.proof_of_delivery.attributable ? '' : 'text-warning'}>
                                    <span className="text-muted-foreground">Proof: </span>
                                    {tracking.proof_of_delivery.label}
                                    {tracking.proof_of_delivery.detail ? ` · ${tracking.proof_of_delivery.detail}` : ''}
                                </p>
                            )}
                            {(tracking.events ?? []).slice(0, 3).map((event, i) => (
                                <p key={i} className="text-[11px] text-muted-foreground">
                                    {event.occurred_at ? new Date(event.occurred_at).toLocaleDateString() : '—'} {event.description}
                                    {event.location ? ` (${event.location})` : ''}
                                </p>
                            ))}
                        </div>
                    )}
                    {tracking?.fetched_at && <p className="text-[10px] text-muted-foreground/70">Checked {ago(tracking.fetched_at)}</p>}
                </div>
            )}

            {failed && <p className="text-[11px] text-destructive">{failed}</p>}
        </div>
    );
}
