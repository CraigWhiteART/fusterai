import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import CommerceAssistNav from './CommerceAssistNav';

interface Props {
    settings: {
        shopify_shop_domain: string | null;
        shopify_token_set: boolean;
        shopify_api_version: string;
        tracking_stale_days: number;
        example_edit_threshold: number;
        preorder_tags: string;
        draft_only: boolean;
    };
}

export default function CommerceAssistSettings({ settings }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        shopify_shop_domain: settings.shopify_shop_domain ?? '',
        shopify_access_token: '',
        shopify_api_version: settings.shopify_api_version,
        tracking_stale_days: settings.tracking_stale_days,
        example_edit_threshold: settings.example_edit_threshold,
        preorder_tags: settings.preorder_tags,
        draft_only: settings.draft_only,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/settings/commerce-assist');
    }

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
                        <h2 className="text-sm font-semibold">Shopify</h2>
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
                            <Label htmlFor="stale">Tracking stale after (days)</Label>
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
                            <Input
                                id="tags"
                                value={data.preorder_tags}
                                onChange={(e) => setData('preorder_tags', e.target.value)}
                            />
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
