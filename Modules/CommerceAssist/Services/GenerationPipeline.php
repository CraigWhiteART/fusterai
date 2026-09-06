<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Events\AiSuggestionReady;
use App\Services\AiSettingsService;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\CommerceAssist\Agents\ClassifyIntentAgent;
use Modules\CommerceAssist\Agents\WriteReplyAgent;
use Modules\CommerceAssist\Models\CommerceIntent;
use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Support\PlainText;
use Throwable;

class GenerationPipeline
{
    public function __construct(
        private readonly ShopifyLookupService $shopify,
        private readonly ApprovedResponseRetriever $examples,
        private readonly KnowledgeRetriever $knowledge,
        private readonly ContextBuilder $context,
        private readonly FactValidator $validator,
        private readonly ConfidenceScorer $confidence,
        private readonly AiSettingsService $aiSettings,
    ) {}

    /**
     * @param  array{persist_suggestion?: bool, status?: string}  $options
     */
    public function run(Conversation $conversation, array $options = []): Generation
    {
        $persistSuggestion = $options['persist_suggestion'] ?? true;
        $status = $options['status'] ?? 'draft';

        IntentCatalog::ensureForWorkspace($conversation->workspace_id);
        $settings = CommerceSetting::forWorkspace($conversation->workspace_id);

        $conversation->loadMissing(['customer', 'mailbox', 'threads.attachments']);

        $customerThread = $this->latestCustomerThread($conversation);
        $customerMessage = PlainText::from($customerThread?->body_plain ?: $customerThread?->body);
        if ($customerMessage === '') {
            $customerMessage = (string) $conversation->subject;
        }

        $snapshot = $this->shopify->lookup($conversation);
        $classification = $this->classify($conversation, $customerMessage, $snapshot);
        [$intent, $subtype] = $this->resolveIntents($conversation->workspace_id, $classification);

        $query = trim($customerMessage.' '.$intent->slug.' '.($subtype?->slug ?? ''));
        $exampleRows = $this->examples->retrieve(
            $conversation->workspace_id,
            $query,
            $intent->slug,
            (int) config('commerce-assist.example_limit', 5),
        );
        $kbDocs = $this->knowledge->retrieve(
            $conversation->workspace_id,
            $query,
            (int) config('commerce-assist.kb_limit', 5),
        );

        $hasPhotos = $conversation->threads->contains(function (Thread $thread) {
            return $thread->attachments->contains(
                fn ($attachment) => str_starts_with((string) $attachment->mime_type, 'image/')
            );
        });

        $built = $this->context->build(
            $conversation,
            $customerMessage,
            $snapshot,
            $classification,
            $intent,
            $subtype,
            $exampleRows,
            $kbDocs,
            $hasPhotos,
            $settings->tracking_stale_days,
        );

        $verifiedBlock = "VERIFIED CUSTOMER DATA AND SHOPIFY DATA AND KNOWLEDGE\n"
            .$this->context->formatShopify($snapshot)."\n"
            .$kbDocs->map(fn ($doc) => $doc->title.': '.PlainText::from($doc->content))->implode("\n");

        $model = null;
        $draft = $this->write($conversation->workspace_id, $built['system'], $built['user'], $model);

        $claims = $this->validator->unsupportedClaims($conversation->workspace_id, $draft, $verifiedBlock);
        if ($claims !== []) {
            $retrySystem = $built['system']."\n\nThe previous draft contained unsupported claims:\n- "
                .implode("\n- ", $claims)
                ."\nRewrite without those claims. If a fact is unknown, say so.";
            $draft = $this->write($conversation->workspace_id, $retrySystem, $built['user'], $model);
            $claims = $this->validator->unsupportedClaims($conversation->workspace_id, $draft, $verifiedBlock);
        }

        $passed = $claims === [];
        $score = $this->confidence->score(
            $settings,
            $intent,
            $subtype,
            $snapshot,
            $claims,
            $exampleRows->all(),
            $kbDocs->all(),
        );

        $generation = Generation::create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_thread_id' => $customerThread?->id,
            'shopify_snapshot_id' => $snapshot->id,
            'ai_draft' => $draft,
            'intent' => $intent->slug,
            'subtype' => $subtype?->slug,
            'sentiment' => $classification['sentiment'] ?? null,
            'model' => $model,
            'examples_retrieved' => $exampleRows->map(fn ($example) => [
                'id' => $example->id,
                'intent' => $example->intent,
                'subtype' => $example->subtype,
            ])->values()->all(),
            'kb_retrieved' => $kbDocs->map(fn ($doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
            ])->values()->all(),
            'sources' => $built['sources'],
            'unsupported_claims' => $claims,
            'validator_passed' => $passed,
            'confidence' => $score['confidence'],
            'safe_to_send' => $score['safe_to_send'],
            'requires_human' => $score['requires_human'],
            'status' => $passed ? $status : 'flagged',
        ]);

        if ($persistSuggestion) {
            $suggestion = AiSuggestion::create([
                'conversation_id' => $conversation->id,
                'thread_id' => $customerThread?->id,
                'type' => 'reply',
                'content' => $draft,
                'model' => $model,
            ]);
            $generation->update(['ai_suggestion_id' => $suggestion->id]);
            broadcast(new AiSuggestionReady($conversation->id, $draft, $suggestion->id));
        }

        return $generation->fresh();
    }

    /**
     * @return array{intent: string, subtype: string, sentiment: string, country: ?string}
     */
    private function classify(Conversation $conversation, string $customerMessage, ShopifySnapshot $snapshot): array
    {
        $fallback = [
            'intent' => 'other',
            'subtype' => 'other',
            'sentiment' => 'unknown',
            'country' => $snapshot->payload['shipping_country'] ?? null,
        ];

        $slugs = IntentCatalog::slugs();
        $shopifySummary = $this->context->formatShopify($snapshot);
        $prompt = "Subject: {$conversation->subject}\nMessage: {$customerMessage}\n\nShopify snapshot:\n{$shopifySummary}";

        try {
            /** @var StructuredAgentResponse $response */
            $response = $this->aiSettings->withWorkspaceCredentials(
                $conversation->workspace_id,
                fn ($lab, $model, $providerOptions = []) => (new ClassifyIntentAgent($slugs))
                    ->withProviderOptions($providerOptions)
                    ->prompt($prompt, provider: $lab, model: $model),
                task: 'auto_categorization',
            );

            $data = $response->toArray();

            return [
                'intent' => (string) ($data['intent'] ?? 'other'),
                'subtype' => (string) ($data['subtype'] ?? $data['intent'] ?? 'other'),
                'sentiment' => (string) ($data['sentiment'] ?? 'unknown'),
                'country' => $data['country'] ?? $fallback['country'],
            ];
        } catch (Throwable $e) {
            Log::warning('Commerce Assist intent classification failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @param  array{intent: string, subtype: string}  $classification
     * @return array{0: CommerceIntent, 1: CommerceIntent|null}
     */
    private function resolveIntents(int $workspaceId, array $classification): array
    {
        $intentSlug = $classification['intent'] ?? 'other';
        $subtypeSlug = $classification['subtype'] ?? $intentSlug;

        if (str_contains($intentSlug, '.')) {
            $subtypeSlug = $intentSlug;
            $intentSlug = $this->parentSlug($intentSlug);
        } elseif (str_contains($subtypeSlug, '.')) {
            $intentSlug = $this->parentSlug($subtypeSlug);
        }

        $intent = IntentCatalog::find($workspaceId, $intentSlug) ?? IntentCatalog::find($workspaceId, 'other');
        if (! $intent) {
            throw new \RuntimeException('Commerce Assist intent catalog is empty.');
        }

        $subtype = $subtypeSlug !== $intentSlug
            ? IntentCatalog::find($workspaceId, $subtypeSlug)
            : $intent;

        return [$intent, $subtype];
    }

    private function parentSlug(string $slug): string
    {
        $prefix = explode('.', $slug)[0];

        return match ($prefix) {
            'tracking' => 'tracking_problem',
            'damage' => 'damaged_product',
            'refund' => 'refund_request',
            default => $prefix,
        };
    }

    private function write(int $workspaceId, string $system, string $user, ?string &$model): string
    {
        $response = $this->aiSettings->withWorkspaceCredentials(
            $workspaceId,
            function ($lab, $resolvedModel, $providerOptions = []) use ($system, $user, &$model) {
                $model = $resolvedModel ?? (is_object($lab) ? $lab->value : (string) $lab);

                return (new WriteReplyAgent($system))
                    ->withProviderOptions($providerOptions)
                    ->prompt($user, provider: $lab, model: $resolvedModel);
            },
            task: 'reply_suggestions',
        );

        return trim((string) ($response->text ?? ''));
    }

    private function latestCustomerThread(Conversation $conversation): ?Thread
    {
        return $conversation->threads
            ->filter(fn (Thread $thread) => $thread->customer_id !== null)
            ->sortByDesc('created_at')
            ->first();
    }
}
