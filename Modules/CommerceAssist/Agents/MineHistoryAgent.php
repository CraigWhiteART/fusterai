<?php

namespace Modules\CommerceAssist\Agents;

use App\Ai\Concerns\HasConfigurableProviderOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[MaxTokens(4096)]
#[Temperature(0.2)]
class MineHistoryAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

    /** @param  list<string>  $slugs */
    public function __construct(
        private readonly array $slugs,
    ) {}

    public function instructions(): string
    {
        $allowed = implode(', ', $this->slugs);

        return <<<INSTRUCTIONS
You extract reusable support knowledge from emails a human agent already sent.

The helpdesk is empty: no knowledge base, no current facts, no approved replies.
Your job is to propose items a human will review. Nothing is published until they accept it.

For each email, you may return zero or more items. Prefer fewer, higher-quality items over dumping every sentence.

Kinds:
- knowledge: Durable policy or process that stays true. Shipping windows, how tracking appears, return rules, what "unfulfilled" means for this store. Write it as a short article (title + body) in third person, present tense. No customer names, emails, order numbers, addresses, or one-off incident details.
- live_fact: True right now but will go stale — current stock, current lead time, a delay, a promo, a pause. One or two sentences. Set time_sensitive true. Strip PII and order numbers.
- approved_reply: A reusable example of how this team replies (tone, structure, what they offer vs escalate). Generalize the customer message and the reply: replace names with a first name placeholder, replace order numbers with "the order", strip emails and addresses. Keep the strategy and wording style.
- skip: The email is unique, a one-off apology, a personal exception, or has no reusable content. Put the reason in body.

Rules:
- Do not invent policies, dates, carriers, or promises that are not in the sent reply.
- If the reply only restates a specific order's Shopify status, skip it unless it also teaches a reusable rule.
- If several emails teach the same fact, propose it once (use the first email_index).
- knowledge and live_fact must never contain PII or order identifiers.
- Intent and subtype must be one of: {$allowed}. Use other if nothing fits.
- product_keywords: comma-separated product or collection words if the email is about a specific item, otherwise empty.
- rationale: one sentence for the reviewer.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->items($schema->object([
                    'email_index' => $schema->integer()
                        ->description('1-based index of the EMAIL block this item came from')
                        ->required(),
                    'kind' => $schema->string()
                        ->enum(['approved_reply', 'knowledge', 'live_fact', 'skip'])
                        ->required(),
                    'title' => $schema->string()->nullable()->description('Short title for knowledge; otherwise null'),
                    'body' => $schema->string()->description('Knowledge article, live fact, or skip reason')->required(),
                    'customer_message' => $schema->string()->nullable()->description('Generalized customer message for approved_reply'),
                    'final_reply' => $schema->string()->nullable()->description('Generalized agent reply for approved_reply'),
                    'intent' => $schema->string()->nullable(),
                    'subtype' => $schema->string()->nullable(),
                    'product_keywords' => $schema->string()->nullable()->description('Comma-separated keywords'),
                    'time_sensitive' => $schema->boolean()->required(),
                    'rationale' => $schema->string()->required(),
                ]))
                ->description('Proposed learnings. Empty if nothing in this batch is reusable.')
                ->required(),
        ];
    }
}
