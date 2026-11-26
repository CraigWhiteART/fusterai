import React from 'react';
import { useForm } from '@inertiajs/react';
import { PackageIcon, ShoppingBagIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';
import { AgentPage, FieldError, SaveButton, Section } from './AgentParts';

interface ConnectionSettings {
    shopify_shop_domain: string | null;
    shopify_resolved_domain: string | null;
    shopify_client_id: string | null;
    has_shopify_client_secret: boolean;
    shopify_configured: boolean;
    shopify_token_expires_at: string | null;
    shopify_granted_scopes: string[];
    shopify_api_version: string;
    tracking_provider: string;
    has_tracking_api_key: boolean;
    tracking_configured: boolean;
    tracking_store_uuid: string | null;
    tracking_store_uuid_default: string | null;
    tracking_stale_days: number;
    preorder_tags: string;
}

interface Props {
    providers: Record<string, string>;
    settings: ConnectionSettings;
}

type TestState = { status: 'idle' | 'testing' | 'ok' | 'fail'; message: string; scopes?: string[] };

/** POST a connection test (JSON). Never run on page load: a Shopify test mints a new access token. */
async function runTest(url: string): Promise<TestState> {
    try {
        const { data } = await window.axios.post<{ ok?: boolean; message?: string; scopes?: string[] }>(url);
        return {
            status: data.ok ? 'ok' : 'fail',
            message: data.message ?? (data.ok ? 'Connected.' : 'Connection failed.'),
            scopes: data.scopes,
        };
    } catch (e: unknown) {
        const status = (e as { response?: { status?: number } }).response?.status;
        return {
            status: 'fail',
            message:
                status === 429
                    ? 'Too many tests. Wait a minute and try again.'
                    : 'Could not reach this server. Check your connection and try again.',
        };
    }
}

function StatusBadge({ configured, test, off }: { configured: boolean; test: TestState; off?: boolean }) {
    if (off) return <Badge variant="outline">Off</Badge>;
    if (test.status === 'ok') return <Badge variant="success">Connected</Badge>;
    if (test.status === 'fail') return <Badge variant="destructive">Test failed</Badge>;
    if (test.status === 'testing') return <Badge variant="secondary">Testing…</Badge>;
    return configured ? <Badge variant="secondary">Saved, not tested</Badge> : <Badge variant="outline">Not connected</Badge>;
}

function TestRow({
    test,
    disabled,
    disabledReason,
    onTest,
}: {
    test: TestState;
    disabled: boolean;
    disabledReason: string;
    onTest: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-3 border-t border-border pt-4">
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={onTest}
                disabled={disabled || test.status === 'testing'}
                title={disabled ? disabledReason : undefined}
            >
                {test.status === 'testing' ? 'Testing…' : 'Test connection'}
            </Button>
            <p
                className={cn(
                    'text-sm',
                    test.status === 'ok' && 'font-medium text-success',
                    test.status === 'fail' && 'text-destructive',
                    (test.status === 'idle' || test.status === 'testing') && 'text-muted-foreground',
                )}
            >
                {disabled ? disabledReason : test.message}
            </p>
        </div>
    );
}

export default function Connections({ providers, settings }: Props) {
    const { data, setData, setDefaults, patch, processing, errors, isDirty } = useForm({
        shopify_shop_domain: settings.shopify_shop_domain ?? '',
        shopify_client_id: settings.shopify_client_id ?? '',
        shopify_client_secret: '',
        shopify_api_version: settings.shopify_api_version,
        tracking_provider: settings.tracking_provider,
        tracking_api_key: '',
        tracking_store_uuid: settings.tracking_store_uuid ?? '',
        tracking_stale_days: settings.tracking_stale_days,
        preorder_tags: settings.preorder_tags,
    });
    const idle: TestState = { status: 'idle', message: 'Click Test to check the saved settings.' };
    const [shopifyTest, setShopifyTest] = React.useState<TestState>(idle);
    const [trackingTest, setTrackingTest] = React.useState<TestState>(idle);
    const scopes = shopifyTest.scopes ?? settings.shopify_granted_scopes;
    const trackingOff = settings.tracking_provider === 'none';

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (data.tracking_provider === 'none' && settings.has_tracking_api_key && settings.tracking_provider !== 'none') {
            if (!window.confirm('Turn carrier tracking off? The saved tracking API key will be deleted.')) return;
        }
        patch('/agent/connections', {
            preserveScroll: true,
            onSuccess: () => {
                setDefaults({ ...data, shopify_client_secret: '', tracking_api_key: '' });
                setData((current) => ({ ...current, shopify_client_secret: '', tracking_api_key: '' }));
                setShopifyTest(idle);
                setTrackingTest(idle);
            },
        });
    }

    async function testShopify() {
        setShopifyTest({ status: 'testing', message: 'Asking Shopify for a token and reading the shop…' });
        setShopifyTest(await runTest('/agent/connections/test-shopify'));
    }

    async function testTracking() {
        setTrackingTest({ status: 'testing', message: 'Calling the tracking provider…' });
        setTrackingTest(await runTest('/agent/connections/test-tracking'));
    }

    return (
        <AgentPage
            title="Connections"
            current="/agent/connections"
            width="max-w-3xl"
            description="Shopify and carrier tracking let the agent quote real order and delivery details instead of guessing."
        >
            <form onSubmit={submit} className="space-y-6">
                <Section
                    title={
                        <span className="flex items-center gap-2">
                            <ShoppingBagIcon className="h-4 w-4 text-primary" /> Shopify
                        </span>
                    }
                    description={
                        <>
                            Use the Client ID and Client secret of your app in the Shopify Dev Dashboard. A 24-hour access token is
                            requested automatically; you never paste a <span className="font-mono">shpat_</span> token.
                        </>
                    }
                    actions={<StatusBadge configured={settings.shopify_configured} test={shopifyTest} />}
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="shop">Shop domain</Label>
                        <Input
                            id="shop"
                            value={data.shopify_shop_domain}
                            onChange={(e) => setData('shopify_shop_domain', e.target.value)}
                            placeholder="your-store.myshopify.com"
                        />
                        <FieldError message={errors.shopify_shop_domain} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="client_id">Client ID</Label>
                            <Input
                                id="client_id"
                                value={data.shopify_client_id}
                                onChange={(e) => setData('shopify_client_id', e.target.value)}
                                placeholder="Dev Dashboard → Settings"
                                autoComplete="off"
                            />
                            <FieldError message={errors.shopify_client_id} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="client_secret">Client secret</Label>
                            <Input
                                id="client_secret"
                                type="password"
                                value={data.shopify_client_secret}
                                onChange={(e) => setData('shopify_client_secret', e.target.value)}
                                placeholder={settings.has_shopify_client_secret ? '•••••••• saved' : 'Dev Dashboard → Settings'}
                                autoComplete="new-password"
                            />
                            <p className="text-xs text-muted-foreground">
                                {settings.has_shopify_client_secret ? 'Leave blank to keep the current secret.' : 'Not saved yet.'}
                            </p>
                            <FieldError message={errors.shopify_client_secret} />
                        </div>
                    </div>
                    <div className="space-y-1.5 sm:max-w-48">
                        <Label htmlFor="version">API version</Label>
                        <Input
                            id="version"
                            value={data.shopify_api_version}
                            onChange={(e) => setData('shopify_api_version', e.target.value)}
                        />
                        <FieldError message={errors.shopify_api_version} />
                    </div>

                    <div className="rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                        <p>
                            <span className="font-medium text-foreground">Granted scopes: </span>
                            {scopes.length > 0 ? (
                                <span className="inline-flex flex-wrap gap-1 align-middle">
                                    {scopes.map((s) => (
                                        <span key={s} className="rounded bg-background px-1.5 py-0.5 font-mono text-[11px]">
                                            {s}
                                        </span>
                                    ))}
                                </span>
                            ) : (
                                'not known yet (shown after the first successful token)'
                            )}
                        </p>
                        {settings.shopify_token_expires_at && (
                            <p className="mt-1">
                                Current token valid until {new Date(settings.shopify_token_expires_at).toLocaleString()}; it renews
                                automatically.
                            </p>
                        )}
                    </div>

                    <TestRow
                        test={shopifyTest}
                        onTest={() => void testShopify()}
                        disabled={!settings.shopify_configured || isDirty}
                        disabledReason={
                            !settings.shopify_configured
                                ? 'Save a shop domain, client ID and client secret first.'
                                : 'Save your changes first, then test.'
                        }
                    />
                </Section>

                <Section
                    title={
                        <span className="flex items-center gap-2">
                            <PackageIcon className="h-4 w-4 text-primary" /> Carrier tracking
                        </span>
                    }
                    description="Looks up the carrier's latest scans before a draft is written, so replies quote what actually happened to the parcel."
                    actions={<StatusBadge configured={settings.tracking_configured} test={trackingTest} off={trackingOff} />}
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="provider">Provider</Label>
                        <select
                            id="provider"
                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                            value={data.tracking_provider}
                            onChange={(e) => setData('tracking_provider', e.target.value)}
                        >
                            {Object.entries(providers).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <FieldError message={errors.tracking_provider} />
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
                                    placeholder={settings.has_tracking_api_key ? '•••••••• saved' : 'API key'}
                                    autoComplete="new-password"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {settings.has_tracking_api_key ? 'Leave blank to keep the current key. ' : ''}
                                    {data.tracking_provider === 'track123' && 'Track123 app → Settings → General → API & Webhook.'}
                                </p>
                                <FieldError message={errors.tracking_api_key} />
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
                                    <p className="text-xs text-muted-foreground">Defaults to the subdomain of the Shopify shop domain.</p>
                                    <FieldError message={errors.tracking_store_uuid} />
                                </div>
                            )}
                        </>
                    )}
                    {settings.tracking_provider !== 'none' && (
                        <TestRow
                            test={trackingTest}
                            onTest={() => void testTracking()}
                            disabled={!settings.tracking_configured || isDirty}
                            disabledReason={
                                !settings.tracking_configured ? 'Save a tracking API key first.' : 'Save your changes first, then test.'
                            }
                        />
                    )}
                </Section>

                <Section title="Order rules" description="Used when the agent reads an order.">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="stale">Treat a parcel as stuck after no scan for (days)</Label>
                            <Input
                                id="stale"
                                type="number"
                                min={1}
                                max={90}
                                value={data.tracking_stale_days}
                                onChange={(e) => setData('tracking_stale_days', Number(e.target.value))}
                            />
                            <FieldError message={errors.tracking_stale_days} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="tags">Pre-order tags</Label>
                            <Input id="tags" value={data.preorder_tags} onChange={(e) => setData('preorder_tags', e.target.value)} />
                            <p className="text-xs text-muted-foreground">
                                Comma separated. Orders or items tagged like this count as pre-orders.
                            </p>
                            <FieldError message={errors.preorder_tags} />
                        </div>
                    </div>
                </Section>

                <div className="flex items-center gap-3">
                    <SaveButton processing={processing} disabled={!isDirty}>
                        Save connections
                    </SaveButton>
                    {isDirty && !processing && <span className="text-xs text-muted-foreground">Unsaved changes</span>}
                </div>
            </form>
        </AgentPage>
    );
}
