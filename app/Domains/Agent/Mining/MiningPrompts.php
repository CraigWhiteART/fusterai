<?php

namespace App\Domains\Agent\Mining;

use App\Domains\Agent\Mining\Pii\PiiScrubber;
use App\Domains\Agent\Models\PlaybookVersion;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Pipeline\UntrustedData;

/**
 * Prompts for the mining calls. System prompts hold only fixed instructions
 * and human-approved content (the playbook catalogue, the current playbook
 * version, the current voice). Everything that came from mail, including
 * labels and summaries a model derived from mail, goes in the user message
 * inside nonce-delimited untrusted-data blocks, scrubbed of identifiers.
 */
class MiningPrompts
{
    public function __construct(
        private readonly PiiScrubber $scrubber,
    ) {}

    // ── Stage B: extraction ───────────────────────────────────────────

    /** @param  list<array{slug: string, name: string, when_text: string}>  $catalogue */
    public function extractSystem(array $catalogue): string
    {
        return <<<TXT
You analyse one past customer-support email conversation of an online store, so the store can train an AI support agent on how its human staff actually handle situations.

Rules:
- Only HUMAN staff turns count as decisions, policies or tone. Turns marked "AI-sent" are not human decisions: ignore them for decisions, statements and tone.
- category: "support" only for a real customer or prospective customer who needs something from the store. Spam, suppliers and other vendors, marketing, partnership pitches, personal mail and automated notices are not support.
- situation: pick the slug from the catalogue below that fits the customer's main need. Only when none fits, invent a short snake_case label (two to four words, general, e.g. "warranty_claim") and set situation_is_new to true.
- statements: policies and facts the human stated (refund window, shipping times per country, warranty, who pays return postage, product facts). Generalise them: never copy names, emails, phone numbers, addresses, order numbers or tracking numbers. said_at is the date of the staff message that stated it.
- outcome: judge only from what happened after the human's reply.
- Identifiers in the data were replaced by placeholders such as [name], [email], [order], [tracking], [phone], [address]. Keep the placeholders; never guess the real values.

Situation catalogue (slug: when it applies):
{$this->catalogueText($catalogue)}
TXT;
    }

    public function extractUser(ConversationDigest $digest, UntrustedData $untrusted): string
    {
        $from = $digest->firstAt()?->toDateString() ?? 'unknown';
        $to = $digest->lastAt()?->toDateString() ?? 'unknown';

        return $untrusted->preamble()."\n\n"
            ."One past conversation ({$from} to {$to}), oldest message first:\n"
            .$untrusted->block('conversation', $this->renderTranscript($digest))
            ."\n\nReturn the structured analysis of this conversation.";
    }

    /** The conversation as the extractor sees it: scrubbed, within the token budget. */
    public function renderTranscript(ConversationDigest $digest): string
    {
        $names = $digest->customerNames;
        $turnMax = max(200, (int) config('agent_mining.extract.turn_max_chars', 3000));
        $budget = max(2000, (int) config('agent_mining.extract.transcript_max_tokens', 5000) * 4);

        $parts = [];
        foreach ($digest->turns as $turn) {
            $who = $turn->isCustomer() ? 'CUSTOMER' : ($digest->isHumanTurn($turn) ? 'STAFF (human)' : 'STAFF (AI-sent, not a human decision)');
            $text = $this->scrubber->scrub($turn->text, $names);
            if (mb_strlen($text) > $turnMax) {
                $text = mb_substr($text, 0, $turnMax).' [truncated]';
            }
            $parts[] = ['head' => "--- {$who} at {$turn->at->toDateString()}".($turn->hasImages ? ' [images attached]' : ''), 'text' => $text];
        }

        // Over budget: shorten the middle turns first, then drop them. The
        // opening request and the latest turns carry the situation and outcome.
        $size = fn () => array_sum(array_map(fn ($p) => mb_strlen($p['head']) + mb_strlen($p['text']) + 2, $parts));
        $count = count($parts);
        for ($i = 1; $i < $count - 2 && $size() > $budget; $i++) {
            $parts[$i]['text'] = mb_substr(preg_replace('/\s+/u', ' ', $parts[$i]['text']) ?? '', 0, 300).' [shortened]';
        }
        $dropped = 0;
        while ($size() > $budget && count($parts) > 3) {
            array_splice($parts, 1, 1);
            $dropped++;
        }

        $lines = [];
        $subject = trim((string) $digest->subject);
        if ($subject !== '') {
            $lines[] = 'Subject: '.$this->scrubber->scrub($subject, $names);
        }
        foreach ($parts as $i => $part) {
            if ($i === 1 && $dropped > 0) {
                $lines[] = "[{$dropped} message(s) in the middle omitted]";
            }
            $lines[] = $part['head']."\n".$part['text'];
        }

        return implode("\n\n", $lines);
    }

    // ── Stage C: label merge ──────────────────────────────────────────

    /** @param  list<array{slug: string, name: string, when_text: string}>  $catalogue */
    public function mergeSystem(array $catalogue, int $maxSituations): string
    {
        return <<<TXT
You organise support situations for an online store. Another model labelled past support conversations; some labels are new. Map every given label to one target:
- an existing slug from the catalogue below when the label means the same situation or is a narrower case of it;
- otherwise a new situation. Merge labels that mean the same thing into one new situation with a general snake_case key, a short name and a one-sentence description of when it applies.
Aim for at most {$maxSituations} situations in total, existing ones included. Keep genuinely different situations apart; merge near-duplicates and wording variants.

Catalogue (slug: when it applies):
{$this->catalogueText($catalogue)}
TXT;
    }

    /** @param  list<array{label: string, count: int, description: string}>  $labels */
    public function mergeUser(array $labels, UntrustedData $untrusted): string
    {
        $lines = array_map(fn ($l) => "{$l['label']} ({$l['count']} conversations): {$l['description']}", $labels);

        return $untrusted->preamble()."\n\n"
            ."New labels, with how many conversations carry each and a description (derived from customer mail):\n"
            .$untrusted->block('labels', implode("\n", $lines))
            ."\n\nReturn one mapping per label.";
    }

    // ── Stage D: synthesis ────────────────────────────────────────────

    /**
     * @param  list<string>  $slugs
     * @param  array<string, string>  $actions
     */
    public function synthSystem(string $situationName, ?string $slug, ?PlaybookVersion $live, array $slugs, array $actions): string
    {
        $current = $live === null
            ? 'There is no playbook for this situation yet: draft one from scratch (all rules and steps are "add").'
            : "The current playbook (slug {$slug}), approved by a human:\n".$this->playbookText($live);
        $actionList = $actions === [] ? '(none)' : implode("\n", array_map(fn ($k, $v) => "- {$k}: {$v}", array_keys($actions), $actions));
        $slugList = implode(', ', $slugs);

        return <<<TXT
You are the training manager for an online store's AI support agent. From how the human staff handled past conversations of one situation ("{$situationName}"), propose what the agent's playbook for that situation should say. A human reviews everything you propose; nothing goes live without approval.

Output rules:
- Propose the smallest set of changes. When a playbook exists, never restate an unchanged rule: use "add" for a new rule, "replace" with the existing rule_id to change one, "retire" with the rule_id to remove one the history contradicts. Same for steps.
- Every rule, step, knowledge item, action and inconsistency cites the ids of the conversations (from the data) that show it. Do not cite ids that are not in the data. A rule nobody demonstrated must not be proposed.
- Rules are IF/THEN: "if" is a concrete condition (or "always"), "then" is what the agent does. Write them for an agent that drafts replies and can look up orders and tracking but cannot act on its own.
- Where the history contradicts itself (a refund window, shipping estimate, warranty or fee that changed over time, or two staff saying different things), list it under inconsistencies with each version and its evidence, recommend the most recent version, and base the rules on the most recent version.
- Knowledge: policies and facts the humans stated, generalised, the most recent version only. Scope is required: global only when it truly applies to every situation; otherwise name the playbook slugs ({$slugList}), product keywords or ISO-2 country codes it applies to.
- suggested_actions: actions the humans took (refunds, replacements, discount codes...). They are listed for the reviewer only and are never switched on by you.
- test_cases: 5 to 10 conversations from the data: mostly representative ones, plus edge cases (unhappy customers, unusual decisions). must_include / must_not_include are short points derived from what the human did.
- Never include names, email addresses, phone numbers, street addresses, order numbers or tracking numbers anywhere in your output. Placeholders like [name] or [order] stand for removed identifiers; leave them out of rules.

{$current}

Actions that exist (for suggested_actions only):
{$actionList}
TXT;
    }

    /**
     * @param  list<array<string, mixed>>  $samples  [{id, date, staff, outcome, decisions, decision_summary, customer_asked, statements, customer_excerpt, reply_excerpt, macro_uses}]
     * @param  list<array<string, mixed>>  $statements  [{id, date, kind, topic, text}]
     * @param  array{count: int, from: string|null, to: string|null, shown: int}  $coverage
     */
    public function synthUser(array $samples, array $statements, array $coverage, UntrustedData $untrusted): string
    {
        $blocks = [];
        foreach ($samples as $s) {
            $lines = [
                "[conversation {$s['id']}] {$s['date']}, staff: {$s['staff']}, outcome: {$s['outcome']}".($s['macro_uses'] > 1 ? ", canned reply used {$s['macro_uses']} times" : ''),
                'Decisions: '.(implode(', ', $s['decisions']) ?: 'none'),
                'Customer asked: '.$s['customer_asked'],
                'Human handled it: '.$s['decision_summary'],
            ];
            foreach ($s['statements'] as $st) {
                $lines[] = "Stated ({$st['kind']}, {$st['topic']}, {$st['said_at']}): {$st['text']}";
            }
            if ($s['customer_excerpt'] !== '') {
                $lines[] = "Customer wrote (excerpt):\n".$s['customer_excerpt'];
            }
            if ($s['reply_excerpt'] !== '') {
                $lines[] = "Human replied (excerpt):\n".$s['reply_excerpt'];
            }
            $blocks[] = implode("\n", $lines);
        }

        $statementLines = array_map(
            fn ($st) => "conversation {$st['id']} | {$st['date']} | {$st['kind']} | {$st['topic']} | {$st['text']}",
            $statements,
        );

        return $untrusted->preamble()."\n\n"
            ."This situation covers {$coverage['count']} conversations from ".($coverage['from'] ?? '?').' to '.($coverage['to'] ?? '?')
            .". {$coverage['shown']} are shown in full (newest weighted, unusual cases always included).\n\n"
            ."Sampled conversations (derived from customer mail and staff replies):\n"
            .$untrusted->block('history', implode("\n\n", $blocks))
            ."\n\nEvery policy or fact statement in this situation, newest first:\n"
            .$untrusted->block('statements', $statementLines === [] ? '(none)' : implode("\n", $statementLines))
            ."\n\nReturn the proposed playbook changes, knowledge, inconsistencies and test cases.";
    }

    // ── Stage E: voice ────────────────────────────────────────────────

    /** @param  list<string>  $staffLabels */
    public function voiceSystem(?VoiceVersion $live, array $staffLabels): string
    {
        $current = $live === null ? '(no voice defined yet: propose every item as "add")' : $this->voiceText($live);
        $staff = implode(', ', $staffLabels);

        return <<<TXT
You define the brand voice for an online store's AI support agent from real replies its human staff wrote ({$staff}). Describe how they actually write: greetings, sign-offs, typical length, warmth, humour, phrases they use and phrases they avoid. A human reviews your proposal.

Output rules:
- Propose changes to the current voice below, not a rewrite: "add" new items, "replace" (with the item id) items the replies contradict, "retire" items nobody follows. Keep item ids exactly as given.
- do_phrases are habits to copy ("Use the customer's first name", "Thank them for their patience when a parcel is late"); dont_phrases are words or phrases to avoid. Greetings and sign-offs are written as templates ("Hi [name],"); a sign-off may name the store or team, never a customer.
- staff_differences: where the staff members write differently (length, formality, humour, sign-off).
- example_phrases: up to 15 short verbatim phrases (under 15 words) that capture the voice. Never include customer names, emails, phone numbers, addresses, order or tracking numbers.
- Placeholders like [name] or [order] stand for removed identifiers.

Current voice:
{$current}
TXT;
    }

    /** @param  list<array{staff: string, situation: string, date: string, text: string}>  $replies */
    public function voiceUser(array $replies, UntrustedData $untrusted): string
    {
        $blocks = array_map(fn ($r) => "[{$r['staff']} | {$r['situation']} | {$r['date']}]\n{$r['text']}", $replies);

        return $untrusted->preamble()."\n\n"
            .count($replies)." replies written by the staff, sampled across staff members, situations and time:\n"
            .$untrusted->block('replies', implode("\n\n", $blocks))
            ."\n\nReturn the proposed voice changes.";
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** @param  list<array{slug: string, name: string, when_text: string}>  $catalogue */
    private function catalogueText(array $catalogue): string
    {
        if ($catalogue === []) {
            return '(empty: every label is new)';
        }

        return implode("\n", array_map(fn ($p) => "- {$p['slug']}: ".preg_replace('/\s+/u', ' ', $p['when_text']), $catalogue));
    }

    private function playbookText(PlaybookVersion $version): string
    {
        $lines = ['When: '.$version->when_text, 'Rules:'];
        foreach ($version->rules ?? [] as $rule) {
            $lines[] = "- [{$rule['id']}] IF {$rule['if']} THEN {$rule['then']}";
        }
        $lines[] = 'Steps:';
        foreach ($version->steps ?? [] as $step) {
            $lines[] = "- [{$step['id']}] {$step['text']}";
        }
        $lines[] = 'Escalation: '.($version->escalation ?? '(none)');
        $lines[] = 'Required evidence: '.(implode(', ', $version->required_evidence ?? []) ?: '(none)');

        return implode("\n", $lines);
    }

    private function voiceText(VoiceVersion $voice): string
    {
        $lines = ['Personality: '.$voice->personality];
        foreach (['do_phrases' => 'Do', 'dont_phrases' => "Don't", 'greetings' => 'Greetings', 'sign_offs' => 'Sign-offs'] as $field => $label) {
            $lines[] = $label.':';
            foreach ($voice->{$field} ?? [] as $item) {
                $lines[] = "- [{$item['id']}] {$item['text']}";
            }
        }
        $lines[] = 'Max words: '.($voice->max_words ?? '(none)');
        $lines[] = 'Length guidance: '.($voice->length_guidance ?? '(none)');

        return implode("\n", $lines);
    }
}
