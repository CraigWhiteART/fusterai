import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import type { PageProps } from '@/types';

type Provider = 'anthropic' | 'openai' | 'openai-compatible' | 'openrouter';

const OPENROUTER_MODELS = [
    { value: 'anthropic/claude-sonnet-4-5', label: 'Claude Sonnet 4.5 (via OpenRouter)' },
    { value: 'anthropic/claude-3.5-sonnet', label: 'Claude 3.5 Sonnet (via OpenRouter)' },
    { value: 'openai/gpt-4o', label: 'GPT-4o (via OpenRouter)' },
    { value: 'openai/o3-mini', label: 'o3-mini (via OpenRouter)' },
    { value: 'google/gemini-2.0-flash-001', label: 'Gemini 2.0 Flash (via OpenRouter)' },
    { value: 'meta-llama/llama-3.3-70b-instruct', label: 'Llama 3.3 70B (via OpenRouter)' },
    { value: 'mistralai/mistral-small-3.1-24b-instruct', label: 'Mistral Small 3.1 (via OpenRouter)' },
];

const ANTHROPIC_MODELS = [
    { value: 'claude-opus-5-5', label: 'Claude Opus 5.5' },
    { value: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5' },
    { value: 'claude-haiku-5-5', label: 'Claude Haiku 5.5 (fastest)' },
    { value: 'claude-fable-5-2', label: 'Claude Fable 5.2 (most capable, highest cost)' },
];

type Role =
    | 'triage'
    | 'writer'
    | 'validator'
    | 'history_extraction'
    | 'simulation_judge'
    | 'manager'
    | 'auto_categorization'
    | 'summarization';

const ROLES: { key: Role; label: string; desc: string }[] = [
    { key: 'triage', label: 'Triage', desc: 'Classifies each email: intent, sentiment, risk.' },
    { key: 'writer', label: 'Writer', desc: 'Drafts the customer reply.' },
    { key: 'validator', label: 'Validator', desc: 'Checks the draft for unsupported claims. Use a different model from the writer.' },
    { key: 'history_extraction', label: 'History extraction', desc: 'Pulls reusable knowledge out of past sent replies.' },
    { key: 'simulation_judge', label: 'Simulation judge', desc: 'Scores simulated drafts against past tickets.' },
    { key: 'manager', label: 'Manager', desc: 'Turns feedback into proposed instruction changes.' },
    { key: 'auto_categorization', label: 'Auto-categorization', desc: 'Sets priority and tags on new conversations.' },
    { key: 'summarization', label: 'Summarization', desc: 'Summarizes closed conversations.' },
];

type EmbeddingsProvider = '' | 'openai' | 'openai-compatible';

const EMBEDDINGS_SOURCE_LABELS: Record<string, string> = {
    workspace: 'Using the embeddings key saved here',
    workspace_main_key: 'Using the main AI key above',
    env: 'Using the server OPENAI_API_KEY (environment fallback)',
};

const OPENAI_MODELS = [
    { value: 'gpt-4o', label: 'GPT-4o' },
    { value: 'gpt-4o-mini', label: 'GPT-4o Mini' },
    { value: 'gpt-4-turbo', label: 'GPT-4 Turbo' },
    { value: 'o3-mini', label: 'o3-mini' },
    { value: 'o1', label: 'o1' },
];

const REASONING_EFFORTS = [
    { value: 'none', label: 'None — disable reasoning / thinking' },
    { value: 'minimal', label: 'Minimal' },
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'xhigh', label: 'Extra high' },
    { value: 'max', label: 'Max' },
];

const PRESETS = [
    { label: 'OpenRouter', url: 'https://openrouter.ai/api/v1' },
    { label: 'Groq', url: 'https://api.groq.com/openai/v1' },
    { label: 'Together', url: 'https://api.together.xyz/v1' },
    { label: 'Ollama (local)', url: 'http://localhost:11434/v1' },
    { label: 'Mistral', url: 'https://api.mistral.ai/v1' },
    { label: 'Perplexity', url: 'https://api.perplexity.ai' },
];

const PROVIDER_LABELS: Record<Provider, string> = {
    anthropic: 'Anthropic',
    openai: 'OpenAI',
    'openai-compatible': 'OpenAI-compatible',
    openrouter: 'OpenRouter',
};

interface AiConfig {
    provider: Provider;
    model: string | null;
    base_url: string | null;
    key_set: boolean;
    features: {
        reply_suggestions: boolean;
        auto_categorization: boolean;
        summarization: boolean;
    };
    task_models?: Partial<Record<Role, string | null>>;
    role_defaults?: Partial<Record<Role, string>>;
    reasoning?: {
        effort: string;
        exclude: boolean;
        max_tokens: number | null;
    };
    rag: { top_k: number; min_score: number };
    embeddings?: {
        provider: EmbeddingsProvider | null;
        model: string | null;
        base_url: string | null;
        dimensions: number;
        key_set: boolean;
        configured: boolean;
        source: string | null;
        active_provider: string | null;
        active_model: string | null;
        env_fallback_enabled: boolean;
    };
}

interface Props extends PageProps {
    aiConfig: AiConfig;
}

function NativeSelect({
    id,
    value,
    onChange,
    children,
    className = '',
}: {
    id?: string;
    value: string;
    onChange: (v: string) => void;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <select
            id={id}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className={`flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring ${className}`}
        >
            {children}
        </select>
    );
}

function Field({ label, hint, children, error }: { label: string; hint?: string; children: React.ReactNode; error?: string }) {
    return (
        <div className="space-y-1.5">
            <label className="text-sm font-medium">{label}</label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

function ModelPicker({
    value,
    onChange,
    provider,
    models,
    allowEmpty = false,
    emptyLabel = 'Use default model',
    error,
}: {
    value: string;
    onChange: (v: string) => void;
    provider: Provider;
    models: { value: string; label: string }[];
    allowEmpty?: boolean;
    emptyLabel?: string;
    error?: string;
}) {
    const isCompatible = provider === 'openai-compatible';
    const isOpenRouter = provider === 'openrouter';

    if (isCompatible) {
        return (
            <div className="space-y-1.5">
                <Input
                    placeholder={allowEmpty ? 'Leave blank to use default' : 'e.g. meta-llama/llama-3.1-70b-instruct'}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    autoComplete="off"
                />
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
        );
    }

    if (isOpenRouter) {
        return (
            <div className="space-y-1.5">
                <NativeSelect value={value} onChange={onChange}>
                    <option value="">{allowEmpty ? emptyLabel : 'Select a model'}</option>
                    {models.map((m) => (
                        <option key={m.value} value={m.value}>
                            {m.label}
                        </option>
                    ))}
                </NativeSelect>
                <Input
                    placeholder="Or type a custom model slug e.g. cohere/command-r-plus"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    autoComplete="off"
                />
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
        );
    }

    return (
        <div className="space-y-1.5">
            <NativeSelect value={value} onChange={onChange}>
                <option value="">{allowEmpty ? emptyLabel : 'Select a model…'}</option>
                {models.map((m) => (
                    <option key={m.value} value={m.value}>
                        {m.label}
                    </option>
                ))}
            </NativeSelect>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

export default function AIConfig({ aiConfig }: Props) {
    const [testStatus, setTestStatus] = useState<'idle' | 'loading' | 'ok' | 'fail'>('idle');
    const [testMessage, setTestMessage] = useState('');
    const [embedStatus, setEmbedStatus] = useState<'idle' | 'loading' | 'ok' | 'fail'>('idle');
    const [embedMessage, setEmbedMessage] = useState('');

    async function testEmbeddings() {
        setEmbedStatus('loading');
        setEmbedMessage('');
        try {
            const res = await fetch('/settings/ai/test-embeddings', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': (document.querySelector('[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                    Accept: 'application/json',
                },
            });
            const body = await res.json();
            setEmbedStatus(body.ok ? 'ok' : 'fail');
            setEmbedMessage(body.message ?? '');
        } catch {
            setEmbedStatus('fail');
            setEmbedMessage('Network error. Could not reach the server.');
        }
    }

    async function testConnection() {
        setTestStatus('loading');
        setTestMessage('');
        try {
            const res = await fetch(route('settings.ai.test'), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': (document.querySelector('[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                    Accept: 'application/json',
                },
            });
            const body = await res.json();
            setTestStatus(body.ok ? 'ok' : 'fail');
            setTestMessage(body.message ?? '');
        } catch {
            setTestStatus('fail');
            setTestMessage('Network error. Could not reach the server.');
        }
    }

    const { data, setData, patch, processing, errors } = useForm({
        provider: aiConfig.provider ?? ('anthropic' as Provider),
        api_key: '',
        model: aiConfig.model ?? '',
        base_url: aiConfig.base_url ?? '',
        feature_reply_suggestions: aiConfig.features?.reply_suggestions ?? true,
        feature_auto_categorization: aiConfig.features?.auto_categorization ?? true,
        feature_summarization: aiConfig.features?.summarization ?? true,
        model_triage: aiConfig.task_models?.triage ?? '',
        model_writer: aiConfig.task_models?.writer ?? '',
        model_validator: aiConfig.task_models?.validator ?? '',
        model_history_extraction: aiConfig.task_models?.history_extraction ?? '',
        model_simulation_judge: aiConfig.task_models?.simulation_judge ?? '',
        model_manager: aiConfig.task_models?.manager ?? '',
        model_auto_categorization: aiConfig.task_models?.auto_categorization ?? '',
        model_summarization: aiConfig.task_models?.summarization ?? '',
        reasoning_effort: aiConfig.reasoning?.effort ?? 'none',
        reasoning_exclude: aiConfig.reasoning?.exclude ?? false,
        reasoning_max_tokens: aiConfig.reasoning?.max_tokens ?? ('' as number | ''),
        rag_top_k: aiConfig.rag?.top_k ?? 5,
        rag_min_score: aiConfig.rag?.min_score ?? 0.7,
        embeddings_provider: (aiConfig.embeddings?.provider ?? '') as EmbeddingsProvider,
        embeddings_api_key: '',
        embeddings_model: aiConfig.embeddings?.model ?? '',
        embeddings_base_url: aiConfig.embeddings?.base_url ?? '',
        embeddings_dimensions: aiConfig.embeddings?.dimensions ?? 1536,
    });

    const embeddings = aiConfig.embeddings;

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        patch('/settings/ai');
    }

    const isCompatible = data.provider === 'openai-compatible';
    const isOpenRouter = data.provider === 'openrouter';
    const models = data.provider === 'anthropic' ? ANTHROPIC_MODELS : data.provider === 'openrouter' ? OPENROUTER_MODELS : OPENAI_MODELS;
    const isAnthropic = data.provider === 'anthropic';

    return (
        <AppLayout>
            <Head title="AI Configuration" />

            <div className="w-full px-6 py-8 space-y-8">
                <div>
                    <h1 className="text-xl font-semibold">AI Configuration</h1>
                    <p className="text-sm text-muted-foreground mt-1">
                        Choose any AI provider. Use different models per task and set a reasoning level when supported.
                    </p>
                </div>

                <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3">
                    <div className="flex-1 text-sm">
                        <span className="font-medium">{PROVIDER_LABELS[data.provider]}</span>
                        {data.model && <span className="text-muted-foreground"> · default {data.model}</span>}
                        {data.reasoning_effort !== 'none' && (
                            <span className="text-muted-foreground"> · reasoning {data.reasoning_effort}</span>
                        )}
                    </div>
                    {aiConfig.key_set ? <Badge variant="success">API key saved</Badge> : <Badge variant="destructive">No API key</Badge>}
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <section className="bg-white border border-border rounded-lg p-6 space-y-5">
                        <h2 className="font-medium text-base">Provider</h2>

                        <Field label="AI Provider">
                            <NativeSelect
                                value={data.provider}
                                onChange={(v) => {
                                    setData('provider', v as Provider);
                                    setData('model', '');
                                    setData('base_url', '');
                                    ROLES.forEach((r) => setData(`model_${r.key}`, ''));
                                }}
                            >
                                <option value="anthropic">Anthropic — Claude models</option>
                                <option value="openai">OpenAI — GPT models</option>
                                <option value="openrouter">OpenRouter — 200+ models (Claude, GPT, Gemini, Llama…)</option>
                                <option value="openai-compatible">OpenAI-compatible — Groq, Together, Ollama…</option>
                            </NativeSelect>
                        </Field>

                        {isCompatible && (
                            <Field
                                label="Base URL"
                                hint="Any provider that speaks the OpenAI API format works here."
                                error={errors.base_url}
                            >
                                <Input
                                    type="url"
                                    placeholder="https://openrouter.ai/api/v1"
                                    value={data.base_url}
                                    onChange={(e) => setData('base_url', e.target.value)}
                                    autoComplete="off"
                                />
                                <div className="flex flex-wrap gap-1.5 mt-2">
                                    {PRESETS.map((p) => (
                                        <button
                                            key={p.url}
                                            type="button"
                                            onClick={() => setData('base_url', p.url)}
                                            className="inline-flex items-center rounded-md border border-border bg-muted/60 px-2 py-0.5 text-xs font-medium hover:bg-muted transition-colors"
                                        >
                                            {p.label}
                                        </button>
                                    ))}
                                </div>
                            </Field>
                        )}

                        <Field
                            label="API Key"
                            hint={
                                aiConfig.key_set
                                    ? 'A key is already saved. Enter a new one to replace it, or leave blank to keep the existing key.'
                                    : 'Enter your API key. It will be encrypted before storage.'
                            }
                            error={errors.api_key}
                        >
                            <Input
                                type="password"
                                placeholder={aiConfig.key_set ? '●●●●●●●● (saved)' : 'sk-…'}
                                value={data.api_key}
                                onChange={(e) => setData('api_key', e.target.value)}
                                autoComplete="off"
                            />
                        </Field>

                        <Field
                            label="Default model"
                            hint={
                                isAnthropic
                                    ? 'Optional. Roles left blank below use their recommended Claude model; this default is only used where no role applies.'
                                    : isCompatible
                                      ? 'Required. Enter the exact model identifier from your provider. Used by every role without its own model.'
                                      : 'Required. Used by every role without its own model.'
                            }
                            error={errors.model}
                        >
                            <ModelPicker
                                value={data.model}
                                onChange={(v) => setData('model', v)}
                                provider={data.provider}
                                models={models}
                            />
                            {isOpenRouter && (
                                <a
                                    href="https://openrouter.ai/models"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-xs text-primary hover:underline"
                                >
                                    Browse all 200+ OpenRouter models →
                                </a>
                            )}
                        </Field>
                    </section>

                    <section className="bg-white border border-border rounded-lg p-6 space-y-5">
                        <div>
                            <h2 className="font-medium text-base">Models by role</h2>
                            <p className="text-xs text-muted-foreground mt-0.5">
                                {isAnthropic
                                    ? 'Leave a role blank to use its recommended model (shown). The validator and simulation judge use a different model from the writer on purpose.'
                                    : 'Leave a role blank to use the default model above.'}
                            </p>
                        </div>

                        {ROLES.map((role) => {
                            const field = `model_${role.key}` as const;
                            const recommended = aiConfig.role_defaults?.[role.key];

                            return (
                                <Field key={role.key} label={role.label} hint={role.desc} error={errors[field]}>
                                    <ModelPicker
                                        value={data[field]}
                                        onChange={(v) => setData(field, v)}
                                        provider={data.provider}
                                        models={models}
                                        allowEmpty
                                        emptyLabel={isAnthropic && recommended ? `Recommended: ${recommended}` : 'Use default model'}
                                    />
                                </Field>
                            );
                        })}
                    </section>

                    <section className="bg-white border border-border rounded-lg p-6 space-y-5">
                        <div>
                            <h2 className="font-medium text-base">Reasoning</h2>
                            <p className="text-xs text-muted-foreground mt-0.5">
                                Controls reasoning tokens for OpenAI reasoning models and the OpenRouter{' '}
                                <code className="text-[11px]">reasoning</code> object.
                            </p>
                        </div>

                        {isAnthropic ? (
                            <p className="text-sm text-muted-foreground">
                                Current Claude models think adaptively on every request and reject fixed thinking budgets, so this setting
                                is not sent to Anthropic.
                            </p>
                        ) : (
                            <Field label="Reasoning effort" error={errors.reasoning_effort}>
                                <NativeSelect value={data.reasoning_effort} onChange={(v) => setData('reasoning_effort', v)}>
                                    {REASONING_EFFORTS.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        )}

                        {data.reasoning_effort !== 'none' && data.provider !== 'anthropic' && (
                            <label className="flex items-start gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={data.reasoning_exclude}
                                    onChange={(e) => setData('reasoning_exclude', e.target.checked)}
                                    className="mt-0.5 h-4 w-4 rounded border-input accent-primary"
                                />
                                <div>
                                    <p className="text-sm font-medium">Exclude reasoning from response</p>
                                    <p className="text-xs text-muted-foreground">
                                        Still uses reasoning tokens internally, but hides them from the returned content when the provider
                                        supports it.
                                    </p>
                                </div>
                            </label>
                        )}
                    </section>

                    <section className="bg-white border border-border rounded-lg p-6 space-y-4">
                        <h2 className="font-medium text-base">Feature Toggles</h2>

                        {[
                            {
                                key: 'feature_reply_suggestions' as const,
                                label: 'Auto-suggest replies',
                                desc: 'Let the AI agent draft on its own when a new customer message arrives (Agent → Settings mode draft / live / shadow). "AI Suggest" in a conversation always works.',
                            },
                            {
                                key: 'feature_auto_categorization' as const,
                                label: 'Auto-categorize',
                                desc: 'Automatically set priority and tags when a conversation is created.',
                            },
                            {
                                key: 'feature_summarization' as const,
                                label: 'Auto-summarize on close',
                                desc: 'Generate a brief summary when a conversation is closed.',
                            },
                        ].map(({ key, label, desc }) => (
                            <label key={key} className="flex items-start gap-3 cursor-pointer group">
                                <input
                                    type="checkbox"
                                    checked={data[key]}
                                    onChange={(e) => setData(key, e.target.checked)}
                                    className="mt-0.5 h-4 w-4 rounded border-input accent-primary"
                                />
                                <div>
                                    <p className="text-sm font-medium">{label}</p>
                                    <p className="text-xs text-muted-foreground">{desc}</p>
                                </div>
                            </label>
                        ))}
                    </section>

                    <section className="bg-white border border-border rounded-lg p-6 space-y-5">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="font-medium text-base">Embeddings</h2>
                                <p className="text-xs text-muted-foreground mt-0.5">
                                    Used for semantic search of the knowledge base and approved replies. Anthropic has no embeddings API, so
                                    this is set separately. Without embeddings, retrieval uses keyword search only.
                                </p>
                            </div>
                            {embeddings?.configured ? (
                                <Badge variant="success">Configured</Badge>
                            ) : (
                                <Badge variant="secondary">Not configured</Badge>
                            )}
                        </div>

                        <p className="text-sm">
                            {embeddings?.configured && embeddings.source ? (
                                <>
                                    {EMBEDDINGS_SOURCE_LABELS[embeddings.source] ?? embeddings.source}
                                    {embeddings.active_model && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {embeddings.active_provider} · {embeddings.active_model}
                                        </span>
                                    )}
                                </>
                            ) : (
                                <span className="text-muted-foreground">
                                    No embeddings source is active. Documents are still indexed for keyword search.
                                </span>
                            )}
                        </p>

                        <Field label="Embeddings provider" error={errors.embeddings_provider}>
                            <NativeSelect
                                value={data.embeddings_provider}
                                onChange={(v) => setData('embeddings_provider', v as EmbeddingsProvider)}
                            >
                                <option value="">Not configured (keyword search only)</option>
                                <option value="openai">OpenAI</option>
                                <option value="openai-compatible">OpenAI-compatible endpoint</option>
                            </NativeSelect>
                        </Field>

                        {data.embeddings_provider !== '' && (
                            <>
                                {data.embeddings_provider === 'openai-compatible' && (
                                    <Field label="Embeddings base URL" error={errors.embeddings_base_url}>
                                        <Input
                                            type="url"
                                            placeholder="http://localhost:11434/v1"
                                            value={data.embeddings_base_url}
                                            onChange={(e) => setData('embeddings_base_url', e.target.value)}
                                            autoComplete="off"
                                        />
                                    </Field>
                                )}

                                <Field
                                    label="Embeddings model"
                                    hint={
                                        data.embeddings_provider === 'openai'
                                            ? 'Leave blank for text-embedding-3-small.'
                                            : 'The model must return 1536-dimensional vectors.'
                                    }
                                    error={errors.embeddings_model}
                                >
                                    <Input
                                        placeholder={data.embeddings_provider === 'openai' ? 'text-embedding-3-small' : 'model name'}
                                        value={data.embeddings_model}
                                        onChange={(e) => setData('embeddings_model', e.target.value)}
                                        autoComplete="off"
                                    />
                                </Field>

                                <Field
                                    label="Embeddings API key"
                                    hint={
                                        embeddings?.key_set
                                            ? 'A key is saved. Enter a new one to replace it, or leave blank to keep it.'
                                            : data.embeddings_provider === data.provider
                                              ? 'Leave blank to use the main AI key above.'
                                              : 'Encrypted before storage.'
                                    }
                                    error={errors.embeddings_api_key}
                                >
                                    <Input
                                        type="password"
                                        placeholder={embeddings?.key_set ? '●●●●●●●● (saved)' : 'sk-…'}
                                        value={data.embeddings_api_key}
                                        onChange={(e) => setData('embeddings_api_key', e.target.value)}
                                        autoComplete="off"
                                    />
                                </Field>

                                <Field
                                    label="Dimensions"
                                    hint="Fixed: the database stores 1536-dimensional vectors."
                                    error={errors.embeddings_dimensions}
                                >
                                    <Input type="number" value={data.embeddings_dimensions} readOnly disabled />
                                </Field>
                            </>
                        )}

                        <div className="flex items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={testEmbeddings}
                                disabled={embedStatus === 'loading' || !embeddings?.configured}
                                title={!embeddings?.configured ? 'Save an embeddings configuration first' : undefined}
                            >
                                {embedStatus === 'loading' ? 'Testing…' : 'Test embeddings'}
                            </Button>
                            {embedStatus === 'ok' && <span className="text-sm text-success font-medium">{embedMessage}</span>}
                            {embedStatus === 'fail' && <span className="text-sm text-destructive">{embedMessage}</span>}
                        </div>
                    </section>

                    <section className="bg-white border border-border rounded-lg p-6 space-y-4">
                        <div>
                            <h2 className="font-medium text-base">RAG Settings</h2>
                            <p className="text-xs text-muted-foreground mt-0.5">Knowledge base retrieval configuration.</p>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <Field label="Top K results" hint="1 – 10" error={errors.rag_top_k}>
                                <Input
                                    type="number"
                                    min={1}
                                    max={10}
                                    step={1}
                                    value={data.rag_top_k}
                                    onChange={(e) => setData('rag_top_k', parseInt(e.target.value, 10))}
                                />
                            </Field>
                            <Field label="Min similarity score" hint="0.0 – 1.0" error={errors.rag_min_score}>
                                <Input
                                    type="number"
                                    min={0}
                                    max={1}
                                    step={0.05}
                                    value={data.rag_min_score}
                                    onChange={(e) => setData('rag_min_score', parseFloat(e.target.value))}
                                />
                            </Field>
                        </div>
                    </section>

                    <div className="flex items-center justify-between gap-4 flex-wrap">
                        <div className="flex items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={testConnection}
                                disabled={testStatus === 'loading' || !aiConfig.key_set}
                                title={!aiConfig.key_set ? 'Save an API key first' : undefined}
                            >
                                {testStatus === 'loading' ? 'Testing…' : 'Test connection'}
                            </Button>
                            {testStatus === 'ok' && <span className="text-sm text-success font-medium">{testMessage}</span>}
                            {testStatus === 'fail' && <span className="text-sm text-destructive">{testMessage}</span>}
                        </div>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save AI settings'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
