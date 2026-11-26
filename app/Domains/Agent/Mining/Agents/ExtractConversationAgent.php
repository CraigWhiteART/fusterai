<?php

namespace App\Domains\Agent\Mining\Agents;

use App\Ai\Concerns\HasConfigurableProviderOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Stage B: one past conversation in, structured facts about it out. Runs on
 * the `history_extraction` role (Haiku: high volume).
 */
// No #[Temperature]: current Claude models reject sampling parameters.
// MaxTokens leaves room for adaptive thinking, which counts toward it.
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-5-5')]
#[MaxTokens(8000)]
#[Timeout(120)]
class ExtractConversationAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

    public const CATEGORIES = ['support', 'spam', 'vendor', 'marketing', 'personal', 'other'];

    public const DECISIONS = [
        'refund_full', 'refund_partial', 'replacement', 'reship', 'store_credit', 'discount_offered',
        'asked_for_photos', 'asked_for_order_number', 'asked_for_details', 'gave_tracking',
        'explained_shipping_times', 'explained_policy', 'troubleshooting', 'product_info',
        'cancelled_order', 'address_change', 'declined', 'escalated', 'promised_follow_up', 'no_action', 'other',
    ];

    public const OUTCOMES = ['thanked', 'resolved_quietly', 'came_back_unhappy', 'unresolved', 'unclear'];

    public function __construct(
        private readonly string $system,
    ) {}

    public function instructions(): string
    {
        return $this->system;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->enum(self::CATEGORIES)
                ->description('support = a real customer or prospective customer needing help from the store')->required(),
            'situation' => $schema->string()
                ->description('An existing situation slug from the catalogue when one fits; otherwise a new short snake_case label')->required(),
            'situation_is_new' => $schema->boolean()->required(),
            'situation_description' => $schema->string()->description('One sentence: the kind of situation, not this customer\'s details')->required(),
            'customer_asked' => $schema->string()->description('What the customer wanted, in one or two sentences, without names or numbers')->required(),
            'decisions' => $schema->array()->items($schema->string()->enum(self::DECISIONS))
                ->description('What the human staff member decided or did')->required(),
            'decision_summary' => $schema->string()->description('How the human handled it, in one or two sentences')->required(),
            'statements' => $schema->array()->items($schema->object([
                'kind' => $schema->string()->enum(['policy', 'fact'])->required(),
                'topic' => $schema->string()->description('Short snake_case topic, e.g. refund_window, shipping_time_au, warranty_length')->required(),
                'text' => $schema->string()->description('The policy or fact as the human stated it, generalised (no names, order or tracking numbers)')->required(),
                'said_at' => $schema->string()->description('Date of the staff message that said it, YYYY-MM-DD')->required(),
            ])->withoutAdditionalProperties())->description('Policies and facts the HUMAN staff stated. Empty when none.')->required(),
            'tone' => $schema->object([
                'greeting' => $schema->string()->nullable()->description('Opening as written, names replaced by [name]; null when none')->required(),
                'sign_off' => $schema->string()->nullable()->description('Closing as written, customer names replaced by [name]; null when none')->required(),
                'formality' => $schema->string()->enum(['casual', 'neutral', 'formal'])->required(),
                'humour' => $schema->boolean()->required(),
                'empathy' => $schema->boolean()->required(),
                'apology' => $schema->boolean()->required(),
                'emoji' => $schema->boolean()->required(),
                'phrases' => $schema->array()->items($schema->string())
                    ->description('Up to 5 short characteristic phrases the human used, verbatim, without names or numbers')->required(),
            ])->withoutAdditionalProperties()->required(),
            'outcome' => $schema->string()->enum(self::OUTCOMES)
                ->description('thanked = customer thanked or confirmed; came_back_unhappy = customer replied dissatisfied after the human reply')->required(),
            'outcome_note' => $schema->string()->description('One short sentence')->required(),
            'language' => $schema->string()->description('ISO 639-1 code of the customer\'s language')->required(),
            'exemplary' => $schema->boolean()->description('True when the human reply is a good model for handling this situation')->required(),
        ];
    }
}
