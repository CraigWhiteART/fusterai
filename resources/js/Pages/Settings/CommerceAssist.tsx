import React, { useCallback, useEffect, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import CommerceAssistNav from './CommerceAssistNav';

interface Props {
    providers: Record<string, string>;
    settings: {
        shopify_shop_domain: string | null;
        shopify_resolved_domain: string | null;
        shopify_token_set: boolean;
        shopify_configured: boolean;
        shopify_api_version: string;
        tracking_provider: string;
        tracking_key_set: boolean;
        tracking_store_uuid: string | null;
        tracking_store_uuid_default: string | null;
        tracking_stale_days: number;
        example_edit_threshold: number;
        preorder_tags: string;
        draft_only: boolean;
    };
}

type ShopifyTestStatus = 'idle' | 'loading' | 'ok' | 'fail' | 'unconfigured';

export default function CommerceAssistSettings({ providers, settings }: Props) {
    const [shopifyStatus, setShopifyStatus] = useState<ShopifyTestStatus>(
        settings.shopify_configured ? 'idle' : 'unconfigured',
    );
    const [shopifyMessage, setShopifyMessage] = useState(
        settings.shopify_configured
            ? `Credentials saved${settings.shopify_resolved_domain ? ` for ${settings.shopify_resolved_domain}` : ''}. Testing…`
            : 'Not connected. Save a shop domain and Admin API token, then test.',
    );

    const { data, setData, post, processing, errors } = useForm({
        shopify_shop_domain: settings.shopify_shop_domain ?? '',
        shopify_access_token: '',
        shopify_api_version: settings.shopify_api_version,
        tracking_provider: settings.tracking_provider,
        tracking_api_key: '',
        tracking_store_uuid: settings.tracking_store_uuid ?? '',
        tracking_stale_days: settings.tracking_stale_days,
        example_edit_threshold: settings.example_edit_threshold,
        preorder_tags: settings.preorder_tags,
        draft_only: settings.draft_only,
    });

    const testShopify = useCallback(async () => {
        if (!settings.shopify_configured) {
            setShopifyStatus('unconfigured');
            setShopifyMessage('Save a shop domain and Admin API token first.');
            return;
        }

        setShopifyStatus('loading');
        setShopifyMessage('Calling Shopify…');
        try {
            const res = await fetch('/settings/commerce-assist/test-shopify', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('[name="csrf-token"]')?.content ?? '',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const body = (await res.json()) as { ok?: boolean; message?: string };
            const ok = Boolean(body.ok);
            setShopifyStatus(ok ? 'ok' : 'fail');
            setShopifyMessage(body.message ?? (ok ? 'Connected.' : 'Connection failed.'));
        } catch {
            setShopifyStatus('fail');
            setShopifyMessage('Network error. Could not reach this server.');
        }
    }, [settings.shopify_configured]);

    useEffect(() => {
        if (settings.shopify_configured) {
            void testShopify();
        }
    }, [settings.shopify_configured, settings.shopify_shop_domain, settings.shopify_token_set, testShopify]);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/settings/commerce-assist');
    }

    const shopifyBadge =
        shopifyStatus === 'ok'
            ? { variant: 'success' as const, label: 'Connected' }
            : shopifyStatus === 'loading' || shopifyStatus === 'idle'
              ? { variant: 'secondary' as const, label: 'Checking…' }
              : shopifyStatus === 'fail'
                ? { variant: 'destructive' as const, label: 'Failed' }
                : { variant: 'outline' as const, label: 'Not connected' };

    return (
        <AppLayout>
            <Head title="Commerce Assist" />
            <div className="w-full px-6 py-8 space-y-8">
                <div className="space-y-2">
                    <h1 className="text-2xl font-bold tracking-tight">Commerce Assist</h1>
                    <p className="text-sm text-muted-foreground">
                        Shopify-backed facts, approved-response examples, and a second-pass fact checker. Drafts only until you turn off
                        draft-only mode.
                    </p>
                    <CommerceAssistNav current="/settings/commerce-assist" />
                </div>

                <form onSubmit={submit} className="max-w-xl space-y-6">
                    <section className="rounded-xl border border-border bg-card p-5 space-y-4">
                        <div className="flex items-start justify-between gap-3">
                            <div className="space-y-1">
                                <h2 className="text-sm font-semibold">Shopify</h2>
                                <p className="text-xs text-muted-foreground">
                                    Shop domain is <span className="font-mono">your-store.myshopify.com</span>. The token must start with{' '}
                                    <span className="font-mono">shpat_</span>.
                                </p>
                            </div>
                            <Badge variant={shopifyBadge.variant}>{shopifyBadge.label}</Badge>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="shop">Shop domain</Label>
                            <Input
                                id="shop"
                                value={data.shopify_shop_domain}
                                onChange={(e) => setData('shopify_shop_domain', e.target.value)}
                                placeholder="store.myshopify.com"
                            />
                            {errors.shopify_shop_domain && <p className="text-xs text-destructive">{errors.shopify_shop_domain}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="token">Admin API access token</Label>
                            <Input
                                id="token"
                                type="password"
                                value={data.shopify_access_token}
                                onChange={(e) => setData('shopify_access_token', e.target.value)}
                                placeholder={settings.shopify_token_set ? 'Token saved — paste a new one to replace' : 'shpat_…'}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="version">API version</Label>
                            <Input
                                id="version"
                                value={data.shopify_api_version}
                                onChange={(e) => setData('shopify_api_version', e.target.value)}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-3 pt-1">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void testShopify()}
                                disabled={shopifyStatus === 'loading' || !settings.shopify_configured}
                                title={!settings.shopify_configured ? 'Save a shop domain and token first' : undefined}
                            >
                                {shopifyStatus === 'loading' ? 'Testing…' : 'Test connection'}
                            </Button>
                            <p
                                className={
                                    shopifyStatus === 'ok'
                                        ? 'text-sm text-success font-medium'
                                        : shopifyStatus === 'fail'
                                          ? 'text-sm text-destructive'
                                          : 'text-sm text-muted-foreground'
                                }
                            >
                                {shopifyMessage}
                            </p>
                        </div>
                    </section>

                    <section className="rounded-xl border border-border bg-card p-5 space-y-4">
                        <div className="space-y-1">
                            <h2 className="text-sm font-semibold">Carrier tracking</h2>
                            <p className="text-xs text-muted-foreground">
                                Looks up live carrier state before a draft is written, so replies quote real scans instead of a bare
                                tracking number. Track123 is the cheapest option for a Shopify store already running its app — lookups are
                                order-keyed, so there is no register-and-wait step.
                            </p>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="provider">Provider</Label>
                            <Select value={data.tracking_provider} onValueChange={(v) => setData('tracking_provider', v)}>
                                <SelectTrigger id="provider">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(providers).map(([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.tracking_provider && <p className="text-xs text-destructive">{errors.tracking_provider}</p>}
                        </div>
                        {data.tracking_provider !== 'none' && (
                            <>
                                <div className="space-y-1.5">
                                    <Label htmlFor="tracking_key">API key</Label>
                                    <Input
                                        id="tracking_key"
                                        type="password"
                                        value={data.tracking_api_key}
                                        onChange={(e) => setData('tracking_api_key', e.target.value)}
                                        placeholder={settings.tracking_key_set ? 'Key saved — paste a new one to replace' : 'API key'}
                                    />
                                    {data.tracking_provider === 'track123' && (
                                        <p className="text-xs text-muted-foreground">
                                            Track123 app → Settings → General → API &amp; Webhook.
                                        </p>
                                    )}
                                </div>
                                {data.tracking_provider === 'track123' && (
                                    <div className="space-y-1.5">
                                        <Label htmlFor="store_uuid">Store subdomain (optional)</Label>
                                        <Input
                                            id="store_uuid"
                                            value={data.tracking_store_uuid}
                                            onChange={(e) => setData('tracking_store_uuid', e.target.value)}
                                            placeholder={settings.tracking_store_uuid_default ?? 'your-store'}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Defaults to the subdomain of the Shopify shop domain above.
                                        </p>
                                    </div>
                                )}
                            </>
                        )}
                    </section>

                    <section className="rounded-xl border border-border bg-card p-5 space-y-4">
                        <h2 className="text-sm font-semibold">Behaviour</h2>
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <p className="text-sm font-medium">Draft only</p>
                                <p className="text-xs text-muted-foreground">Never auto-send. Always leave a draft for a human.</p>
                            </div>
                            <Switch checked={data.draft_only} onCheckedChange={(v) => setData('draft_only', v)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="stale">Escalate when no carrier scan for (days)</Label>
                            <Input
                                id="stale"
                                type="number"
                                min={1}
                                value={data.tracking_stale_days}
                                onChange={(e) => setData('tracking_stale_days', Number(e.target.value))}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="threshold">Nominate as example when edit exceeds (%)</Label>
                            <Input
                                id="threshold"
                                type="number"
                                min={1}
                                max={100}
                                value={data.example_edit_threshold}
                                onChange={(e) => setData('example_edit_threshold', Number(e.target.value))}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="tags">Preorder tags (comma separated)</Label>
                            <Input id="tags" value={data.preorder_tags} onChange={(e) => setData('preorder_tags', e.target.value)} />
                        </div>
                    </section>

                    <Button type="submit" disabled={processing}>
                        Save settings
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
