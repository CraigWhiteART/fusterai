<?php

namespace App\Domains\Agent\Services;

/**
 * Seed content for a workspace's first voice and playbooks. Everything here
 * is a starting point the owner is expected to edit.
 */
class StarterContent
{
    /** @return array<string, mixed> */
    public static function voice(): array
    {
        return [
            'personality' => "STARTING POINT - edit this to sound like your team.\n"
                .'Friendly, direct and conversational, like a helpful person at a small online store writing to one customer. '
                .'Concise: answer the question in the first sentence, then say what happens next and who does it. '
                .'Warm without gushing; apologise at most once, and only when something actually went wrong.',
            'do_phrases' => [
                ['id' => 'do-first-name', 'text' => 'Use the customer\'s first name when it is known.'],
                ['id' => 'do-answer-first', 'text' => 'Answer the question in the first sentence.'],
                ['id' => 'do-next-step', 'text' => 'End with the next step and who will take it.'],
                ['id' => 'do-short-paragraphs', 'text' => 'Keep paragraphs to one to three sentences.'],
                ['id' => 'do-one-apology', 'text' => 'Apologise at most once, and only for a real problem.'],
            ],
            'dont_phrases' => [
                ['id' => 'dont-understand-frustration', 'text' => 'I completely understand your frustration'],
                ['id' => 'dont-apologize-inconvenience', 'text' => 'I apologize for any inconvenience'],
                ['id' => 'dont-apologise-inconvenience', 'text' => 'I apologise for any inconvenience'],
                ['id' => 'dont-rest-assured', 'text' => 'Rest assured'],
                ['id' => 'dont-finds-you-well', 'text' => 'I hope this email finds you well'],
                ['id' => 'dont-patience-understanding', 'text' => 'Thank you for your patience and understanding'],
                ['id' => 'dont-hesitate', 'text' => 'Please don\'t hesitate to reach out'],
                ['id' => 'dont-valued-customer', 'text' => 'valued customer'],
                ['id' => 'dont-as-an-ai', 'text' => 'As an AI'],
            ],
            'greetings' => [
                ['id' => 'greet-hi-name', 'text' => 'Hi {first name},'],
            ],
            'sign_offs' => [
                ['id' => 'sign-cheers', 'text' => 'Cheers,'],
                ['id' => 'sign-thanks', 'text' => 'Thanks,'],
            ],
            'max_words' => 150,
            'length_guidance' => 'Usually 40-120 words. Longer only when the customer asked several things.',
        ];
    }

    /** Better triage descriptions for the seeded intents. */
    public const WHEN_TEXT = [
        'order_status' => 'The customer asks where their order is or what its status is, without reporting a tracking problem.',
        'order_status.unfulfilled' => 'The customer asks about an order that has not shipped yet, e.g. when it will be sent.',
        'order_status.fulfilled' => 'The customer asks about an order that has already shipped and wants its status or tracking.',
        'tracking_problem' => 'The customer reports a problem with the tracking or delivery progress of a shipped parcel.',
        'tracking.no_updates' => 'The customer says tracking has not updated or the parcel seems stuck in transit.',
        'tracking.delivered_not_received' => 'Tracking shows delivered but the customer says they did not receive the parcel.',
        'preorder_status' => 'The customer asks about a preorder item: when it will ship or arrive.',
        'address_change' => 'The customer wants to change or correct the shipping address on an order.',
        'refund_request' => 'The customer asks for a refund for a reason other than a change of mind.',
        'refund.change_of_mind' => 'The customer wants to return an item or get a refund because they changed their mind.',
        'damaged_product' => 'The customer reports a damaged or faulty product.',
        'damage.screen' => 'The customer reports a cracked, damaged or faulty screen or display.',
        'damage.shipping' => 'The customer reports the item or its packaging arrived damaged in transit.',
        'technical_support' => 'The customer needs help setting up, using or troubleshooting a product they own.',
        'duties_tariffs' => 'The customer asks about customs, import duties, taxes or tariffs on an order.',
        'product_question' => 'The customer asks a pre-sale question about a product (compatibility, features, stock).',
        'wholesale' => 'The sender asks about wholesale, bulk or reseller pricing.',
        'influencer' => 'The sender proposes an influencer, sponsorship or collaboration deal.',
        'spam' => 'Unsolicited marketing, SEO or link offers, or other spam that needs no reply.',
        'other' => 'Anything that no other playbook covers.',
    ];

    /**
     * Free-text rules ("- If X, Y." bullets) to [{id, if, then}] with ids
     * derived from the playbook slug and the rule text, so re-running the
     * conversion yields the same ids.
     *
     * @return list<array{id: string, if: string, then: string}>
     */
    public static function rulesFromText(string $slug, string $text): array
    {
        $bullets = [];
        $current = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = rtrim($line);
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/u', $line, $m) === 1) {
                if ($current !== null) {
                    $bullets[] = $current;
                }
                $current = trim($m[1]);
            } else {
                $current = $current === null ? trim($line) : $current.' '.trim($line);
            }
        }
        if ($current !== null) {
            $bullets[] = $current;
        }

        $rules = [];
        $seen = [];
        foreach ($bullets as $bullet) {
            $if = 'always';
            $then = $bullet;
            if (preg_match('/^(?:if|when)\s+(.+?),\s*(.+)$/iu', $bullet, $m) === 1) {
                $if = trim($m[1]);
                $then = trim($m[2]);
                $then = mb_strtoupper(mb_substr($then, 0, 1)).mb_substr($then, 1);
            }

            $id = 'r-'.substr(sha1($slug.'|'.mb_strtolower($bullet)), 0, 8);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rules[] = ['id' => $id, 'if' => $if, 'then' => $then];
        }

        return $rules;
    }
}
