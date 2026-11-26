<?php

namespace App\Domains\Agent\Mining;

use App\Domains\Agent\Mining\Pii\PiiScrubber;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Pipeline\TranscriptBuilder;
use App\Domains\Agent\Pipeline\TranscriptTurn;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Enums\ThreadType;
use App\Models\User;

/**
 * Reads a conversation for mining. A staff turn counts as human-written
 * unless the AI sent it (origin ai), the human sent the AI draft unchanged
 * (feedback event sent_as_is), or it is word for word an accepted legacy AI
 * suggestion. The content hash covers every turn's role, time and text, so
 * any change to the conversation re-extracts it.
 */
class DigestBuilder
{
    public function __construct(
        private readonly TranscriptBuilder $transcripts,
    ) {}

    public function build(Conversation $conversation): ConversationDigest
    {
        $transcript = $this->transcripts->fromConversation($conversation);
        $turns = $transcript->turns;

        $threads = Thread::query()
            ->where('conversation_id', $conversation->id)
            ->where('type', ThreadType::Message->value)
            ->get(['id', 'user_id', 'customer_id', 'origin', 'meta'])
            ->keyBy('id');

        $sentAsIs = FeedbackEvent::query()
            ->where('conversation_id', $conversation->id)
            ->where('kind', FeedbackEvent::KIND_SENT_AS_IS)
            ->whereNotNull('thread_id')
            ->pluck('thread_id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $acceptedSuggestions = AiSuggestion::query()
            ->where('conversation_id', $conversation->id)
            ->where('accepted', true)
            ->pluck('content')
            ->map(fn ($c) => self::normaliseSpace(strip_tags((string) $c)))
            ->filter()
            ->flip()
            ->all();

        $userIds = $threads->pluck('user_id')->filter()->unique()->values()->all();
        $users = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->pluck('name', 'id');

        $humanReplies = [];
        $humanThreadIds = [];
        $customerNames = [];

        $customerIds = $threads->pluck('customer_id')->filter()->push($conversation->customer_id)->filter()->unique()->values()->all();
        foreach (Customer::query()->whereIn('id', $customerIds)->get(['id', 'name', 'email']) as $customer) {
            $customerNames = [...$customerNames, ...PiiScrubber::namesFrom($customer->name, $customer->email)];
        }

        foreach ($turns as $turn) {
            /** @var TranscriptTurn $turn */
            $thread = $turn->threadId !== null ? $threads->get($turn->threadId) : null;
            if ($thread === null) {
                continue;
            }
            $meta = is_array($thread->meta) ? $thread->meta : [];

            if ($turn->isCustomer()) {
                $customerNames = [...$customerNames, ...PiiScrubber::namesFrom($meta['from_name'] ?? null, $meta['from_email'] ?? null)];

                continue;
            }

            $isAi = $thread->origin === Thread::ORIGIN_AI
                || isset($sentAsIs[$thread->id])
                || ($acceptedSuggestions !== [] && isset($acceptedSuggestions[self::normaliseSpace($turn->text)]));
            if ($isAi || trim($turn->text) === '') {
                continue;
            }

            [$key, $name] = $this->staffIdentity($thread, $meta, $users->all());
            $humanThreadIds[$thread->id] = true;
            $humanReplies[] = [
                'thread_id' => $thread->id,
                'text' => $turn->text,
                'at' => $turn->at,
                'staff_key' => $key,
                'staff_name' => $name,
            ];
        }

        $hash = hash('sha256', (string) json_encode(array_map(fn (TranscriptTurn $t) => [
            $t->role, $t->at->toIso8601String(), $t->text, $t->origin, $t->threadId !== null && isset($humanThreadIds[$t->threadId]),
        ], $turns), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        return new ConversationDigest(
            conversationId: (int) $conversation->id,
            subject: $conversation->subject,
            turns: $turns,
            humanReplies: $humanReplies,
            humanThreadIds: $humanThreadIds,
            customerNames: array_values(array_unique($customerNames)),
            contentHash: $hash,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int|string, string>  $users
     * @return array{0: string, 1: string}
     */
    private function staffIdentity(Thread $thread, array $meta, array $users): array
    {
        if ($thread->user_id !== null) {
            return ['u:'.$thread->user_id, (string) ($users[$thread->user_id] ?? 'Staff')];
        }

        $email = strtolower(trim((string) ($meta['from_email'] ?? '')));
        if ($email !== '') {
            $name = trim((string) ($meta['from_name'] ?? ''));

            return ['e:'.substr(sha1($email), 0, 12), $name !== '' && ! str_contains($name, '@') ? $name : 'Staff'];
        }

        return ['staff', 'Staff'];
    }

    private static function normaliseSpace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $text);
    }
}
