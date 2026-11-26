<?php

namespace App\Domains\Agent\Proposals;

use App\Ai\Retrieval\HybridRetriever;
use App\Domains\Agent\Agents\ConflictJudgeAgent;
use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\ProposalChange;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Pipeline\UntrustedData;
use App\Domains\Agent\Pipeline\UsageMeter;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Policy\PolicyResolver;
use App\Services\AiSettingsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks a proposal against everything already live, before approval.
 *
 *   1. deterministic: the same phrase in do and don't; exact duplicates;
 *      a rule that offers an action whose effective policy
 *      (agent_action_policies via PolicyResolver) is `never`, or that the
 *      playbook may not propose. Both are warnings: every action defaults to
 *      `never` on install, and the agent hands over when it cannot act.
 *   2. retrieval: similar voice items, rules of the target playbook and of
 *      other playbooks (term overlap), knowledge (HybridRetriever: full-text,
 *      plus pgvector when embeddings exist) and example replies
 *   3. one LLM call (`manager` role) over the shortlisted pairs:
 *      contradiction | duplicate | subsumes | violates (examples) | none
 *
 * Contradictions block approval until a human resolves them (keep old /
 * replace old / scope the new one); duplicates and subsumes are warnings.
 * Precedence, enforced in resolutions and stated to the writer:
 * code limits > action policy > knowledge > playbook > voice > examples.
 * Examples that violate the new instruction are flagged for retirement,
 * because few-shot examples outvote instructions.
 */
class ConflictChecker
{
    public const PRECEDENCE = ['code_limit', 'action_policy', 'knowledge', 'playbook', 'voice', 'example'];

    private const MAX_PAIRS = 24;

    private const PER_ITEM = 5;

    /** Words that mean the rule is permissive about the action. */
    private const ACTION_TERMS = [
        'refund' => ['refund'],
        'cancel_order' => ['cancel the order', 'cancel their order', 'cancel it', 'cancellation'],
        'discount_code' => ['discount code', 'discount', 'coupon', 'promo code'],
        'replacement' => ['replacement', 'send a new', 'reship', 'resend'],
        'change_address' => ['change the address', 'update the address', 'change their address', 'address change'],
        'fulfilment_hold' => ['hold the order', 'hold fulfilment', 'pause fulfilment', 'hold fulfillment'],
    ];

    public function __construct(
        private readonly AiSettingsService $ai,
        private readonly HybridRetriever $retriever,
        private readonly OpsApplier $ops,
        private readonly PolicyResolver $policies,
    ) {}

    /**
     * Run the check and store the report on the proposal. Resolutions of
     * conflicts that are found again (same pair) are kept.
     *
     * @return array<string, mixed>
     */
    public function check(Proposal $proposal, bool $useLlm = true): array
    {
        $proposal->loadMissing('changes');
        $ws = $proposal->workspace_id;
        $previous = [];
        foreach ((array) ($proposal->conflicts['items'] ?? []) as $item) {
            $previous[$item['id']] = $item;
        }
        foreach ((array) ($proposal->conflicts['example_flags'] ?? []) as $flag) {
            $previous[$flag['id']] = $flag;
        }

        $context = $this->context($ws, $proposal);
        $newItems = $this->newItems($proposal, $context);

        $items = [];
        foreach ($newItems as $new) {
            foreach ($this->deterministic($new, $context) as $conflict) {
                $items[$conflict['id']] = $conflict;
            }
        }

        $pairs = [];
        foreach ($newItems as $new) {
            foreach ($this->shortlist($ws, $new, $context) as $existing) {
                $pairId = substr(hash('sha256', $new['key'].'|'.$existing['key']), 0, 12);
                if (! isset($pairs[$pairId]) && ! isset($items['c-'.$pairId])) {
                    $pairs[$pairId] = ['new' => $new, 'existing' => $existing];
                }
            }
        }
        $pairs = array_slice($pairs, 0, self::MAX_PAIRS, true);

        $flags = $this->phraseExampleFlags($ws, $newItems, $context);
        $llm = ['status' => 'skipped', 'error' => null, 'pairs' => count($pairs), 'cost_micro_usd' => 0];

        if ($useLlm && $pairs !== []) {
            try {
                [$relations, $cost] = $this->judge($ws, $pairs);
                $llm = ['status' => 'ok', 'error' => null, 'pairs' => count($pairs), 'cost_micro_usd' => $cost];
                foreach ($relations as $pairId => $rel) {
                    $pair = $pairs[$pairId] ?? null;
                    if ($pair === null || $rel['relation'] === 'none') {
                        continue;
                    }
                    if ($pair['existing']['target_type'] === ProposalChange::TARGET_EXAMPLE) {
                        if ($rel['relation'] === 'violates' || $rel['relation'] === 'contradiction') {
                            $flags['f-'.$pairId] = $this->flag('f-'.$pairId, $pair['existing'], $rel['explanation'], $pair['new']['text']);
                        }

                        continue;
                    }
                    if ($rel['relation'] === 'violates') {
                        continue;
                    }
                    $items['c-'.$pairId] = $this->conflict('c-'.$pairId, $rel['relation'], $pair['new'], $pair['existing'], $rel['explanation']);
                }
            } catch (Throwable $e) {
                Log::warning('Proposal conflict check failed', ['proposal_id' => $proposal->id, 'error' => $e->getMessage()]);
                $llm = ['status' => 'failed', 'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300), 'pairs' => count($pairs), 'cost_micro_usd' => 0];
                $items['check_failed'] = [
                    'id' => 'check_failed', 'type' => 'check_failed', 'severity' => 'blocking', 'change_index' => null,
                    'new' => null, 'existing' => null,
                    'explanation' => 'The AI contradiction check could not run. Re-run the check, or acknowledge to approve without it.',
                    'options' => ['acknowledge'], 'resolution' => null, 'resolved_by' => null, 'resolved_at' => null, 'note' => null,
                ];
            }
        }

        // Keep earlier human resolutions of the same conflict / flag.
        foreach ($items as $id => $item) {
            if (isset($previous[$id]['resolution']) && $id !== 'check_failed') {
                $items[$id] = [...$item, ...array_intersect_key($previous[$id], array_flip(['resolution', 'resolved_by', 'resolved_at', 'note']))];
            }
        }
        foreach ($flags as $id => $flag) {
            if (isset($previous[$id]['resolution'])) {
                $flags[$id] = [...$flag, ...array_intersect_key($previous[$id], array_flip(['resolution', 'resolved_by', 'resolved_at']))];
            }
        }

        $report = [
            'checked_at' => now()->toIso8601String(),
            'llm' => $llm,
            'items' => array_values($items),
            'example_flags' => array_values($flags),
            'precedence' => self::PRECEDENCE,
            'summary' => [
                'blocking' => count(array_filter($items, fn ($i) => $i['severity'] === 'blocking' && $i['resolution'] === null)),
                'warnings' => count(array_filter($items, fn ($i) => $i['severity'] === 'warning' && $i['resolution'] === null)),
                'examples_flagged' => count(array_filter($flags, fn ($f) => $f['resolution'] === null)),
            ],
        ];

        $proposal->forceFill(['conflicts' => $report, 'conflicts_checked_at' => now()])->save();

        return $report;
    }

    // ── Context ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function context(int $ws, Proposal $proposal): array
    {
        $voiceId = AgentSetting::forWorkspace($ws)->live_voice_version_id;
        $voice = $voiceId !== null ? VoiceVersion::find($voiceId) : null;
        $playbooks = Playbook::with('liveVersion')->where('workspace_id', $ws)->whereNull('archived_at')->whereNotNull('live_version_id')->get();
        $voiceAfter = null;
        foreach ($proposal->changes as $change) {
            if ($change->target_type === ProposalChange::TARGET_VOICE) {
                $voiceAfter = $change->after;
            }
        }

        $exampleIds = [];
        if ($proposal->run_id !== null) {
            foreach ((array) (AgentRun::find($proposal->run_id)?->trace['examples'] ?? []) as $ref) {
                if (preg_match('/^X(\d+)$/', (string) $ref, $m) === 1) {
                    $exampleIds[] = (int) $m[1];
                }
            }
        }

        return [
            'voice' => $voice?->toContentArray(),
            'voice_after' => $voiceAfter ?? $voice?->toContentArray(),
            'playbooks' => $playbooks,
            'workspace_id' => $ws,
            'run_example_ids' => $exampleIds,
            'objected' => array_values(array_filter([
                (string) ($proposal->evidence['selected_text'] ?? ''),
                ...array_map('strval', (array) ($proposal->evidence['test_expectation']['must_not_include'] ?? [])),
            ], fn ($s) => trim($s) !== '' && mb_strlen($s) <= 200)),
        ];
    }

    /**
     * The instruction texts this proposal adds or changes.
     *
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function newItems(Proposal $proposal, array $context): array
    {
        $items = [];
        foreach ($proposal->changes as $index => $change) {
            $after = (array) $change->after;
            $playbookSlug = $change->target_type === ProposalChange::TARGET_PLAYBOOK ? (string) ($after['slug'] ?? '') : null;

            foreach ((array) $change->diff as $entry) {
                if (($entry['after'] ?? null) === null || in_array($entry['op'], ['create_playbook', 'create_test_case', 'retire_example', 'replace_example'], true)) {
                    continue;
                }
                $field = (string) ($entry['field'] ?? '');
                if (in_array($field, ['max_words', 'required_evidence', 'scope', 'valid_from', 'valid_until', 'kind', 'title'], true)) {
                    continue;
                }
                $text = (string) $entry['after'];
                if ($field === 'rules' && $entry['item_id'] !== null) {
                    $rule = collect($after['rules'] ?? [])->firstWhere('id', $entry['item_id']);
                    $text = $rule !== null ? $this->ops->ruleText($rule) : $text;
                }

                $items[] = [
                    'key' => $change->target_type.':'.($change->target_id ?? 'new'.$index).':'.$field.':'.($entry['item_id'] ?? $entry['op']),
                    'change_index' => $index,
                    'target_type' => $change->target_type,
                    'target_id' => $change->target_id,
                    'playbook_slug' => $playbookSlug,
                    'field' => $field,
                    'item_id' => $entry['item_id'],
                    'op' => $entry['op'],
                    'text' => $text,
                    'label' => $this->sourceLabel($change->target_type, $field, $playbookSlug),
                ];
            }
        }

        return $items;
    }

    // ── Deterministic ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function deterministic(array $new, array $context): array
    {
        $out = [];
        $voice = (array) ($context['voice_after'] ?? []);

        // Same phrase in do and don't.
        if (in_array($new['field'], ['do_phrases', 'dont_phrases'], true)) {
            $opposite = $new['field'] === 'do_phrases' ? 'dont_phrases' : 'do_phrases';
            foreach ((array) ($voice[$opposite] ?? []) as $item) {
                if ($this->overlaps($new['text'], (string) $item['text'])) {
                    $existing = ['key' => "voice:{$opposite}:{$item['id']}", 'target_type' => 'voice', 'target_id' => null, 'field' => $opposite, 'item_id' => $item['id'], 'text' => $item['text'], 'label' => $this->sourceLabel('voice', $opposite, null)];
                    $out[] = $this->conflict('d-'.substr(hash('sha256', $new['key'].$existing['key']), 0, 12), 'do_dont', $new, $existing,
                        'The same phrase is both something to say and something never to say.');
                }
            }
        }

        // Exact duplicates anywhere.
        foreach ($this->allExisting($context) as $existing) {
            if ($existing['key'] === $this->existingKeyOf($new)) {
                continue;
            }
            if ($this->normalize($existing['text']) === $this->normalize($new['text'])) {
                $out[] = $this->conflict('u-'.substr(hash('sha256', $new['key'].$existing['key']), 0, 12), 'duplicate', $new, $existing, 'Exactly the same text already exists.');
            }
        }

        // Rules that offer an action: switched off (`never`) or not allowed in
        // the playbook both warn. The agent hands over in either case, so the
        // rule does no harm until a person turns the action on.
        if ($new['field'] === 'rules' || $new['field'] === 'steps') {
            $playbook = $context['playbooks']->firstWhere('slug', $new['playbook_slug']);
            $allowed = $playbook?->liveVersion->allowed_actions ?? [];
            foreach ($this->actionsOffered($new['text']) as $action) {
                $policy = $this->policies->resolve((int) $context['workspace_id'], $action, $playbook?->id)->mode;
                $existing = ['key' => "policy:{$action}", 'target_type' => 'action_policy', 'target_id' => null, 'field' => 'policy', 'item_id' => $action, 'text' => "Action \"{$action}\" policy: {$policy}", 'label' => 'Action policy'];
                if ($policy === ActionPolicy::NEVER) {
                    $out[] = $this->conflict("a-{$action}-".substr(hash('sha256', $new['key']), 0, 8), 'action_never', $new, $existing,
                        "This rule offers \"{$action}\", but this action is currently switched off, so the agent will hand over instead. A person can turn it on under Actions > Policies.");
                } elseif (! in_array($action, $allowed, true)) {
                    $out[] = $this->conflict("n-{$action}-".substr(hash('sha256', $new['key']), 0, 8), 'action_not_allowed', $new, $existing,
                        "This rule offers \"{$action}\", which this playbook may not propose (allowed actions are set by a person in the playbook settings). The agent will hand over instead.");
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function actionsOffered(string $text): array
    {
        $lower = mb_strtolower($text);
        $found = [];
        foreach (self::ACTION_TERMS as $action => $terms) {
            foreach ($terms as $term) {
                $pos = mb_strpos($lower, $term);
                if ($pos === false) {
                    continue;
                }
                // "never refund", "do not offer a refund", "no refunds" are restrictions, not offers.
                $before = mb_substr($lower, max(0, $pos - 30), min(30, $pos));
                if (preg_match("/\b(never|not|no|don't|dont|without|refuse|decline)\b/u", $before) === 1) {
                    continue;
                }
                $found[] = $action;
                break;
            }
        }

        return array_values(array_unique($found));
    }

    // ── Retrieval shortlist ──────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function allExisting(array $context): array
    {
        $out = [];
        foreach (['do_phrases', 'dont_phrases'] as $field) {
            foreach ((array) ($context['voice'][$field] ?? []) as $item) {
                $out[] = ['key' => "voice:{$field}:{$item['id']}", 'target_type' => 'voice', 'target_id' => null, 'field' => $field, 'item_id' => $item['id'], 'text' => (string) $item['text'], 'label' => $this->sourceLabel('voice', $field, null)];
            }
        }
        foreach ($context['playbooks'] as $playbook) {
            foreach ((array) ($playbook->liveVersion->rules ?? []) as $rule) {
                $out[] = ['key' => "playbook:{$playbook->id}:rules:{$rule['id']}", 'target_type' => 'playbook', 'target_id' => $playbook->id, 'playbook_slug' => $playbook->slug, 'field' => 'rules', 'item_id' => $rule['id'], 'text' => $this->ops->ruleText($rule), 'label' => $this->sourceLabel('playbook', 'rules', $playbook->slug)];
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $new */
    private function existingKeyOf(array $new): string
    {
        return $new['target_type'] === 'voice'
            ? "voice:{$new['field']}:{$new['item_id']}"
            : "{$new['target_type']}:{$new['target_id']}:{$new['field']}:{$new['item_id']}";
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function shortlist(int $ws, array $new, array $context): array
    {
        $scored = [];
        $self = $this->existingKeyOf($new);
        foreach ($this->allExisting($context) as $existing) {
            if ($existing['key'] === $self) {
                continue;
            }
            $score = $this->similarity($new['text'], $existing['text']);
            // Same playbook / voice gets a nudge: that is where contradictions bite.
            if (($existing['playbook_slug'] ?? null) !== null && $existing['playbook_slug'] === $new['playbook_slug']) {
                $score += 0.05;
            }
            if ($score >= 0.12) {
                $scored[] = [$score, $existing];
            }
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);
        $out = array_map(fn ($s) => $s[1], array_slice($scored, 0, self::PER_ITEM));

        $query = $this->searchQuery($new['text']);
        if ($query !== '') {
            $knowledge = KnowledgeItem::query()->where('workspace_id', $ws)->where('status', KnowledgeItem::STATUS_ACTIVE);
            if ($new['target_type'] === ProposalChange::TARGET_KNOWLEDGE && $new['target_id'] !== null) {
                $knowledge->whereKeyNot($new['target_id']);
            }
            foreach ($this->retriever->search($knowledge, ['title', 'body'], $query, null, 4, 0.6, ['id', 'title', 'body'], 'embedding', 'tsv') as $item) {
                $out[] = ['key' => "knowledge:{$item->id}", 'target_type' => 'knowledge', 'target_id' => $item->id, 'field' => 'body', 'item_id' => null,
                    'text' => mb_substr(trim(($item->title ? $item->title.': ' : '').$item->body), 0, 1200), 'label' => 'Knowledge #'.$item->id];
            }

            // Example replies that may teach the opposite (playbook-scoped for playbook changes).
            if (in_array($new['field'], ['rules', 'steps', 'do_phrases', 'dont_phrases', 'personality', 'body'], true)) {
                $examples = AgentExample::query()->where('workspace_id', $ws)->where('status', 'active');
                if ($new['playbook_slug'] !== null) {
                    $examples->where('playbook_slug', $new['playbook_slug']);
                }
                $found = $this->retriever->search($examples, ['customer_message', 'reply'], $query, null, 3, 0.55, ['id', 'reply'], 'embedding', 'tsv')->all();
                $found = [...$found, ...AgentExample::where('workspace_id', $ws)->where('status', 'active')->whereIn('id', $context['run_example_ids'])->get(['id', 'reply'])->all()];
                foreach ($found as $example) {
                    $out[] = $this->exampleRef($example);
                }
            }
        }

        $unique = [];
        foreach ($out as $existing) {
            $unique[$existing['key']] = $existing;
        }

        return array_values($unique);
    }

    /** @return array<string, mixed> */
    private function exampleRef(AgentExample $example): array
    {
        return ['key' => "example:{$example->id}", 'target_type' => 'example', 'target_id' => $example->id, 'field' => 'reply', 'item_id' => null,
            'text' => mb_substr($example->reply, 0, 1500), 'label' => 'Example reply X'.$example->id];
    }

    /**
     * Examples whose reply contains a phrase the proposal (or the human)
     * says never to use.
     *
     * @param  list<array<string, mixed>>  $newItems
     * @param  array<string, mixed>  $context
     * @return array<string, array<string, mixed>>
     */
    private function phraseExampleFlags(int $ws, array $newItems, array $context): array
    {
        $phrases = $context['objected'];
        $slugs = [];
        foreach ($newItems as $new) {
            if ($new['field'] === 'dont_phrases') {
                $phrases[] = $new['text'];
            }
            if ($new['playbook_slug'] !== null) {
                $slugs[] = $new['playbook_slug'];
            }
        }
        $phrases = array_values(array_unique(array_filter($phrases, fn ($p) => mb_strlen(trim($p)) >= 3)));
        if ($phrases === []) {
            return [];
        }

        $flags = [];
        $query = AgentExample::where('workspace_id', $ws)->where('status', 'active');
        foreach ($query->get(['id', 'reply', 'playbook_slug']) as $example) {
            foreach ($phrases as $phrase) {
                if (mb_stripos($example->reply, $phrase) !== false) {
                    $id = 'p-'.$example->id.'-'.substr(hash('sha256', mb_strtolower($phrase)), 0, 8);
                    $flags[$id] = $this->flag($id, $this->exampleRef($example), "The example reply contains \"{$phrase}\", which the new instruction rules out. Few-shot examples outweigh instructions, so retire it.", $phrase);
                    break;
                }
            }
        }

        return $flags;
    }

    // ── LLM ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array{new: array<string, mixed>, existing: array<string, mixed>}>  $pairs
     * @return array{0: array<string, array{relation: string, explanation: string}>, 1: int}
     */
    private function judge(int $ws, array $pairs): array
    {
        $untrusted = new UntrustedData;
        $system = implode("\n", [
            'You check a proposed change to a customer-support AI agent\'s instructions against existing instructions, knowledge and example replies.',
            'For each pair decide the relation of NEW to EXISTING:',
            '- contradiction: following both is impossible, or they tell the agent opposite things in the same situation.',
            '- duplicate: they say the same thing.',
            '- subsumes: one fully covers the other (broader or stricter version of the same instruction).',
            '- violates: (only when EXISTING is an example reply) the example reply does what the new instruction forbids, or fails to do what it requires.',
            '- none: unrelated or compatible (different situations, or complementary).',
            'Different conditions ("when X") that cannot both hold are not a contradiction. Be strict: only call contradiction when a reply cannot satisfy both.',
            'Precedence when they conflict: code limits > action policy > knowledge > playbook > voice > examples.',
            'Return one entry per pair, using the pair_id exactly as given.',
        ]);

        $lines = [$untrusted->preamble()];
        foreach ($pairs as $id => $pair) {
            $lines[] = "pair_id: {$id}";
            $lines[] = 'NEW ('.$pair['new']['label'].'): '.$untrusted->block('proposed', $pair['new']['text']);
            $lines[] = 'EXISTING ('.$pair['existing']['label'].'): '.$untrusted->block('existing', $pair['existing']['text']);
            $lines[] = '';
        }

        $response = $this->ai->withWorkspaceCredentials(
            $ws,
            fn ($lab, $model, $options = []) => (new ConflictJudgeAgent($system))
                ->withProviderOptions($options)
                ->prompt(implode("\n", $lines), provider: $lab, model: $model),
            task: 'manager',
        );
        $cost = UsageMeter::microUsd($response->meta->model ?? null, $response->usage);
        $data = method_exists($response, 'toArray') ? $response->toArray() : [];

        $out = [];
        foreach ((array) ($data['pairs'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $relation = in_array($row['relation'] ?? null, ConflictJudgeAgent::RELATIONS, true) ? (string) $row['relation'] : 'none';
            $out[(string) ($row['pair_id'] ?? '')] = ['relation' => $relation, 'explanation' => mb_substr((string) ($row['explanation'] ?? ''), 0, 500)];
        }

        return [$out, $cost];
    }

    // ── Shapes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function conflict(string $id, string $type, array $new, array $existing, string $explanation): array
    {
        $blocking = in_array($type, ['contradiction', 'do_dont'], true);
        $options = match ($type) {
            'action_never', 'action_not_allowed' => ['keep_old', 'acknowledge'],
            'contradiction', 'do_dont' => $this->canScope($new) ? ['keep_old', 'replace_old', 'scope_new'] : ['keep_old', 'replace_old'],
            'subsumes' => ['keep_old', 'replace_old', 'keep_both'],
            default => ['keep_old', 'keep_both'],
        };
        if (! in_array($existing['target_type'], ['voice', 'playbook', 'knowledge'], true)) {
            $options = array_values(array_diff($options, ['replace_old']));
        }

        return [
            'id' => $id,
            'type' => $type,
            'severity' => $blocking ? 'blocking' : 'warning',
            'change_index' => $new['change_index'],
            'new' => array_intersect_key($new, array_flip(['target_type', 'target_id', 'playbook_slug', 'field', 'item_id', 'op', 'text', 'label'])),
            'existing' => array_intersect_key($existing, array_flip(['target_type', 'target_id', 'playbook_slug', 'field', 'item_id', 'text', 'label'])),
            'existing_precedence' => $this->rank($existing['target_type']),
            'new_precedence' => $this->rank($new['target_type']),
            'explanation' => $explanation,
            'options' => $options,
            'resolution' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'note' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $example
     * @return array<string, mixed>
     */
    private function flag(string $id, array $example, string $reason, string $phrase): array
    {
        return [
            'id' => $id,
            'example_id' => $example['target_id'],
            'reply_excerpt' => mb_substr((string) $example['text'], 0, 400),
            'reason' => $reason,
            'phrase' => mb_substr($phrase, 0, 300),
            'options' => ['retire', 'keep'],
            'resolution' => null,
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }

    /** @param  array<string, mixed>  $new */
    private function canScope(array $new): bool
    {
        return ($new['field'] === 'rules' && in_array($new['op'], ['add_rule', 'replace_rule'], true))
            || ($new['target_type'] === ProposalChange::TARGET_KNOWLEDGE);
    }

    private function rank(string $type): int
    {
        $index = array_search($type === 'action_policy' ? 'action_policy' : $type, self::PRECEDENCE, true);

        return $index === false ? count(self::PRECEDENCE) : (int) $index;
    }

    private function sourceLabel(string $type, string $field, ?string $slug): string
    {
        return match ($type) {
            'voice' => 'Brand voice ('.str_replace('_', ' ', $field).')',
            'playbook' => 'Playbook '.($slug ?? '?').' ('.$field.')',
            'knowledge' => 'Knowledge',
            default => Str::headline($type),
        };
    }

    private function overlaps(string $a, string $b): bool
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }

    private function normalize(string $s): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
    }

    /** @return list<string> */
    private function terms(string $s): array
    {
        $stop = ['the', 'and', 'for', 'that', 'this', 'with', 'they', 'them', 'their', 'when', 'what', 'have', 'from', 'your', 'you', 'are', 'not', 'any', 'only', 'always', 'never', 'customer', 'reply', 'order'];
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($s), $m);

        return array_values(array_unique(array_diff($m[0], $stop)));
    }

    private function similarity(string $a, string $b): float
    {
        $ta = $this->terms($a);
        $tb = $this->terms($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }
        $shared = count(array_intersect($ta, $tb));

        return $shared / count(array_unique([...$ta, ...$tb]));
    }

    private function searchQuery(string $text): string
    {
        return implode(' ', array_slice($this->terms($text), 0, 12));
    }
}
