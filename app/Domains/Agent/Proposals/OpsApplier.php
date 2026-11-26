<?php

namespace App\Domains\Agent\Proposals;

use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\ProposalChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Applies field-level ops to one target's content, in memory. Pure: no
 * database access, so the same code validates a proposal, builds the
 * preview overlay, renders the diff and produces what approval writes.
 *
 * Every op addresses list items by their stable id; new items get an id
 * here and the id is written back into the returned op, so re-applying the
 * same ops (rebase) yields the same ids. Format: docs/agent-proposals.md.
 */
final class OpsApplier
{
    public const VOICE_LIST_FIELDS = ['do_phrases', 'dont_phrases', 'greetings', 'sign_offs'];

    public const VOICE_SET_FIELDS = ['personality', 'max_words', 'length_guidance'];

    public const PLAYBOOK_LIST_FIELDS = ['steps'];

    public const PLAYBOOK_SET_FIELDS = ['when_text', 'escalation', 'required_evidence'];

    public const KNOWLEDGE_SET_FIELDS = ['kind', 'title', 'body', 'scope', 'valid_from', 'valid_until'];

    /**
     * Permissions, limits, modes and rollout are never AI-proposed. Any op
     * touching these is set aside as a plain suggestion for a human.
     */
    public const PROTECTED_FIELDS = [
        'allowed_actions', 'mode', 'rollout_percent', 'always_human', 'priority', 'caps', 'kill_switch',
        'auto_send_delay_seconds', 'link_allowlist', 'action_policies', 'action_policy', 'policy', 'limits', 'status',
    ];

    public const PROTECTED_OPS = [
        'set_mode', 'set_rollout', 'set_rollout_percent', 'allow_action', 'disallow_action', 'add_allowed_action',
        'remove_allowed_action', 'set_allowed_actions', 'set_action_policy', 'set_policy', 'set_caps', 'set_cap',
        'set_limit', 'set_kill_switch', 'set_always_human', 'set_priority', 'set_auto_send',
    ];

    /** @var array<string, list<string>> */
    public const OPS = [
        ProposalChange::TARGET_VOICE => ['add_do', 'add_dont', 'add_greeting', 'add_sign_off', 'replace_item', 'remove_item', 'set_field'],
        ProposalChange::TARGET_PLAYBOOK => ['create_playbook', 'add_rule', 'replace_rule', 'remove_rule', 'add_step', 'replace_item', 'remove_item', 'set_field'],
        ProposalChange::TARGET_KNOWLEDGE => ['create_knowledge', 'set_field', 'retire_knowledge'],
        ProposalChange::TARGET_EXAMPLE => ['retire_example', 'replace_example'],
        ProposalChange::TARGET_TEST_CASE => ['create_test_case'],
    ];

    private const VOICE_ADD = [
        'add_do' => ['do_phrases', 'do'],
        'add_dont' => ['dont_phrases', 'dont'],
        'add_greeting' => ['greetings', 'greet'],
        'add_sign_off' => ['sign_offs', 'sign'],
    ];

    private const FIELD_LABELS = [
        'do_phrases' => 'do phrase',
        'dont_phrases' => "don't phrase",
        'greetings' => 'greeting',
        'sign_offs' => 'sign-off',
        'steps' => 'step',
        'rules' => 'rule',
        'personality' => 'personality',
        'max_words' => 'maximum words',
        'length_guidance' => 'length guidance',
        'when_text' => '"when" description',
        'escalation' => 'escalation',
        'required_evidence' => 'required evidence',
        'kind' => 'kind',
        'title' => 'title',
        'body' => 'text',
        'scope' => 'scope',
        'valid_from' => 'valid from',
        'valid_until' => 'valid until',
    ];

    private const PROTECTED_CREATE_KEYS = ['allowed_actions', 'mode', 'rollout_percent', 'always_human', 'priority'];

    /**
     * Why this op may not be proposed, or null when it may.
     *
     * @param  array<string, mixed>  $op
     */
    public function forbiddenReason(array $op): ?string
    {
        $name = (string) ($op['op'] ?? '');
        if (in_array($name, self::PROTECTED_OPS, true)) {
            return 'Action permissions, limits, modes and rollout are changed by a person in settings, never by an AI proposal.';
        }
        if ($name === 'set_field' && in_array((string) ($op['field'] ?? ''), self::PROTECTED_FIELDS, true)) {
            return "The field \"{$op['field']}\" controls permissions, limits, mode or rollout and is changed by a person in settings.";
        }

        return null;
    }

    /**
     * Strip protected keys an op carries alongside allowed content (e.g. a
     * create_playbook that also sets allowed_actions). Returns the cleaned op
     * and the removed part (null when nothing was removed).
     *
     * @param  array<string, mixed>  $op
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    public function stripProtected(array $op): array
    {
        if (($op['op'] ?? null) !== 'create_playbook') {
            return [$op, null];
        }

        $removed = [];
        foreach (self::PROTECTED_CREATE_KEYS as $key) {
            if (array_key_exists($key, $op)) {
                if ($op[$key] !== null && $op[$key] !== [] && $op[$key] !== '') {
                    $removed[$key] = $op[$key];
                }
                unset($op[$key]);
            }
        }

        return [$op, $removed === [] ? null : ['op' => 'create_playbook', 'slug' => $op['slug'] ?? null, ...$removed]];
    }

    /**
     * @param  array<string, mixed>|null  $before  Current content (null for creates)
     * @param  list<array<string, mixed>>  $ops
     * @return array{after: array<string, mixed>|null, diff: list<array<string, mixed>>, ops: list<array<string, mixed>>, errors: list<string>}
     */
    public function apply(string $targetType, ?array $before, array $ops, string $path = 'ops'): array
    {
        $errors = [];
        $diff = [];
        $out = [];
        $content = $before;

        if (! isset(self::OPS[$targetType])) {
            return ['after' => $before, 'diff' => [], 'ops' => [], 'errors' => ["{$path}: unknown target type \"{$targetType}\"."]];
        }
        if ($ops === []) {
            $errors[] = "{$path}: no ops.";
        }

        foreach (array_values($ops) as $i => $op) {
            $at = "{$path}.{$i}";
            if (! is_array($op)) {
                $errors[] = "{$at}: an op must be an object.";

                continue;
            }
            $name = (string) ($op['op'] ?? '');
            if (! in_array($name, self::OPS[$targetType], true)) {
                $errors[] = "{$at}: op \"{$name}\" is not valid for target \"{$targetType}\".";

                continue;
            }

            try {
                [$content, $entries, $op] = match ($targetType) {
                    ProposalChange::TARGET_VOICE => $this->voiceOp($content, $op, $i),
                    ProposalChange::TARGET_PLAYBOOK => $this->playbookOp($content, $op, $i),
                    ProposalChange::TARGET_KNOWLEDGE => $this->knowledgeOp($content, $op, $i),
                    ProposalChange::TARGET_EXAMPLE => $this->exampleOp($content, $op),
                    default => $this->testCaseOp($content, $op, $i),
                };
            } catch (OpError $e) {
                $errors[] = "{$at}: ".$e->getMessage();

                continue;
            }

            $out[] = $op;
            foreach ($entries as $entry) {
                $diff[] = $entry;
            }
        }

        return ['after' => $content, 'diff' => $diff, 'ops' => $out, 'errors' => $errors];
    }

    // ── Voice ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $c
     * @param  array<string, mixed>  $op
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function voiceOp(?array $c, array $op, int $i): array
    {
        if ($c === null) {
            throw new OpError('there is no live voice to change.');
        }
        $name = (string) $op['op'];

        if (isset(self::VOICE_ADD[$name])) {
            [$field, $prefix] = self::VOICE_ADD[$name];
            $text = $this->text($op['text'] ?? null, 500);
            $id = $this->newId($op['id'] ?? null, $prefix, $c[$field] ?? [], $i);
            if ($this->findByText($c[$field] ?? [], $text) !== null) {
                throw new OpError("\"{$text}\" is already a {$this->label($field)}.");
            }
            $c[$field] = [...($c[$field] ?? []), ['id' => $id, 'text' => $text]];
            $op = ['op' => $name, 'id' => $id, 'text' => $text];

            return [$c, [$this->entry($name, $field, $id, 'Add '.$this->label($field), null, $text)], $op];
        }

        if ($name === 'replace_item' || $name === 'remove_item') {
            $field = (string) ($op['field'] ?? '');
            if (! in_array($field, self::VOICE_LIST_FIELDS, true)) {
                throw new OpError('field must be one of '.implode(', ', self::VOICE_LIST_FIELDS).'.');
            }

            return $this->listItemOp($c, $op, $field);
        }

        // set_field
        $field = (string) ($op['field'] ?? '');
        if (! in_array($field, self::VOICE_SET_FIELDS, true)) {
            throw new OpError('set_field on voice supports '.implode(', ', self::VOICE_SET_FIELDS).'.');
        }
        $value = match ($field) {
            'personality' => $this->text($op['value'] ?? null, 4000),
            'max_words' => $this->maxWords($op['value'] ?? null),
            default => $this->nullableText($op['value'] ?? null, 2000),
        };
        $old = $c[$field] ?? null;
        $c[$field] = $value;

        return [$c, [$this->entry('set_field', $field, null, 'Change '.$this->label($field), $this->str($old), $this->str($value))], ['op' => 'set_field', 'field' => $field, 'value' => $value]];
    }

    // ── Playbook ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $c
     * @param  array<string, mixed>  $op
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function playbookOp(?array $c, array $op, int $i): array
    {
        $name = (string) $op['op'];

        if ($name === 'create_playbook') {
            if ($c !== null) {
                throw new OpError('create_playbook must be the first op of a change with no target_id.');
            }
            $slug = Str::slug((string) ($op['slug'] ?? $op['name'] ?? ''), '_');
            if ($slug === '' || strlen($slug) > 80) {
                throw new OpError('create_playbook needs a slug (letters, digits, underscores).');
            }
            $nameText = $this->text($op['name'] ?? Str::headline($slug), 120);
            $rules = [];
            foreach (array_values((array) ($op['rules'] ?? [])) as $r => $rule) {
                $rule = is_array($rule) ? $rule : ['then' => (string) $rule];
                $rules[] = [
                    'id' => $this->newId($rule['id'] ?? null, 'r', $rules, $i * 100 + $r),
                    'if' => $this->condition($rule['if'] ?? null),
                    'then' => $this->text($rule['then'] ?? null, 1000),
                ];
            }
            $steps = [];
            foreach (array_values((array) ($op['steps'] ?? [])) as $s => $step) {
                $text = $this->text(is_array($step) ? ($step['text'] ?? null) : $step, 1000);
                $steps[] = ['id' => $this->newId(is_array($step) ? ($step['id'] ?? null) : null, 'step', $steps, $i * 100 + $s), 'text' => $text];
            }
            $content = [
                'slug' => $slug,
                'name' => $nameText,
                'when_text' => $this->text($op['when_text'] ?? null, 2000),
                'rules' => $rules,
                'steps' => $steps,
                'allowed_actions' => [],
                'required_evidence' => $this->requiredEvidence($op['required_evidence'] ?? []),
                'escalation' => $this->nullableText($op['escalation'] ?? null, 2000),
            ];
            $op = ['op' => 'create_playbook', ...array_diff_key($content, ['allowed_actions' => true])];

            $entries = [$this->entry('create_playbook', null, null, "Create playbook \"{$nameText}\"", null, 'When: '.$content['when_text'])];
            foreach ($rules as $rule) {
                $entries[] = $this->entry('add_rule', 'rules', $rule['id'], 'Add rule', null, $this->ruleText($rule));
            }
            foreach ($steps as $step) {
                $entries[] = $this->entry('add_step', 'steps', $step['id'], 'Add step', null, $step['text']);
            }

            return [$content, $entries, $op];
        }

        if ($c === null) {
            throw new OpError('a playbook change needs target_id (the playbook) unless it starts with create_playbook.');
        }

        switch ($name) {
            case 'add_rule':
                $rule = [
                    'id' => $this->newId($op['id'] ?? null, 'r', $c['rules'] ?? [], $i),
                    'if' => $this->condition($op['if'] ?? null),
                    'then' => $this->text($op['then'] ?? $op['text'] ?? null, 1000),
                ];
                foreach ($c['rules'] ?? [] as $existing) {
                    if ($this->same($existing['then'] ?? '', $rule['then']) && $this->same($existing['if'] ?? '', $rule['if'])) {
                        throw new OpError("the rule \"{$rule['then']}\" already exists ({$existing['id']}).");
                    }
                }
                $c['rules'] = [...($c['rules'] ?? []), $rule];

                return [$c, [$this->entry('add_rule', 'rules', $rule['id'], 'Add rule', null, $this->ruleText($rule))], ['op' => 'add_rule', ...$rule]];

            case 'replace_rule':
                $id = (string) ($op['id'] ?? '');
                $index = $this->indexOf($c['rules'] ?? [], $id);
                if ($index === null) {
                    throw new OpError("no rule with id \"{$id}\" in the live version.");
                }
                $old = $c['rules'][$index];
                $new = [
                    'id' => $id,
                    'if' => array_key_exists('if', $op) ? $this->condition($op['if']) : (string) $old['if'],
                    'then' => $this->text($op['then'] ?? $op['text'] ?? null, 1000),
                ];
                $c['rules'][$index] = $new;

                return [$c, [$this->entry('replace_rule', 'rules', $id, 'Change rule', $this->ruleText($old), $this->ruleText($new))], ['op' => 'replace_rule', ...$new]];

            case 'remove_rule':
                $id = (string) ($op['id'] ?? '');
                $index = $this->indexOf($c['rules'] ?? [], $id);
                if ($index === null) {
                    throw new OpError("no rule with id \"{$id}\" in the live version.");
                }
                $old = $c['rules'][$index];
                array_splice($c['rules'], $index, 1);

                return [$c, [$this->entry('remove_rule', 'rules', $id, 'Remove rule', $this->ruleText($old), null)], ['op' => 'remove_rule', 'id' => $id]];

            case 'add_step':
                $text = $this->text($op['text'] ?? null, 1000);
                $id = $this->newId($op['id'] ?? null, 'step', $c['steps'] ?? [], $i);
                $c['steps'] = [...($c['steps'] ?? []), ['id' => $id, 'text' => $text]];

                return [$c, [$this->entry('add_step', 'steps', $id, 'Add step', null, $text)], ['op' => 'add_step', 'id' => $id, 'text' => $text]];

            case 'replace_item':
            case 'remove_item':
                $field = (string) ($op['field'] ?? '');
                if (! in_array($field, self::PLAYBOOK_LIST_FIELDS, true)) {
                    throw new OpError('on a playbook, replace_item / remove_item support field "steps" (use replace_rule / remove_rule for rules).');
                }

                return $this->listItemOp($c, $op, $field);

            default: // set_field
                $field = (string) ($op['field'] ?? '');
                if (! in_array($field, self::PLAYBOOK_SET_FIELDS, true)) {
                    throw new OpError('set_field on a playbook supports '.implode(', ', self::PLAYBOOK_SET_FIELDS).'.');
                }
                $value = match ($field) {
                    'when_text' => $this->text($op['value'] ?? null, 2000),
                    'required_evidence' => $this->requiredEvidence($op['value'] ?? []),
                    default => $this->nullableText($op['value'] ?? null, 2000),
                };
                $old = $c[$field] ?? null;
                $c[$field] = $value;

                return [$c, [$this->entry('set_field', $field, null, 'Change '.$this->label($field), $this->str($old), $this->str($value))], ['op' => 'set_field', 'field' => $field, 'value' => $value]];
        }
    }

    // ── Knowledge ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $c
     * @param  array<string, mixed>  $op
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function knowledgeOp(?array $c, array $op, int $i): array
    {
        $name = (string) $op['op'];

        if ($name === 'create_knowledge') {
            if ($c !== null) {
                throw new OpError('create_knowledge must be the first op of a change with no target_id.');
            }
            $content = [
                'kind' => $this->kind($op['kind'] ?? 'fact'),
                'title' => $this->nullableText($op['title'] ?? null, 255),
                'body' => $this->text($op['body'] ?? null, 20000),
                'scope' => $this->scope($op['scope'] ?? null),
                'valid_from' => $this->date($op['valid_from'] ?? null),
                'valid_until' => $this->date($op['valid_until'] ?? null),
                'status' => KnowledgeItem::STATUS_ACTIVE,
            ];
            $this->checkWindow($content);

            return [$content, [$this->entry('create_knowledge', 'body', null, 'Add knowledge'.($content['title'] ? " \"{$content['title']}\"" : ''), null, $content['body'])], ['op' => 'create_knowledge', ...array_diff_key($content, ['status' => true])]];
        }

        if ($c === null) {
            throw new OpError('a knowledge change needs target_id (the item) unless it starts with create_knowledge.');
        }
        if (($c['status'] ?? null) === KnowledgeItem::STATUS_RETIRED) {
            throw new OpError('that knowledge item is already retired.');
        }

        if ($name === 'retire_knowledge') {
            $c['status'] = KnowledgeItem::STATUS_RETIRED;

            return [$c, [$this->entry('retire_knowledge', 'status', null, 'Retire knowledge'.(! empty($c['title']) ? " \"{$c['title']}\"" : ''), (string) $c['body'], null)], ['op' => 'retire_knowledge']];
        }

        $field = (string) ($op['field'] ?? '');
        if (! in_array($field, self::KNOWLEDGE_SET_FIELDS, true)) {
            throw new OpError('set_field on knowledge supports '.implode(', ', self::KNOWLEDGE_SET_FIELDS).'.');
        }
        $value = match ($field) {
            'kind' => $this->kind($op['value'] ?? null),
            'title' => $this->nullableText($op['value'] ?? null, 255),
            'body' => $this->text($op['value'] ?? null, 20000),
            'scope' => $this->scope($op['value'] ?? null),
            default => $this->date($op['value'] ?? null),
        };
        $old = $c[$field] ?? null;
        $c[$field] = $value;
        $this->checkWindow($c);

        return [$c, [$this->entry('set_field', $field, null, 'Change knowledge '.$this->label($field), $this->str($old), $this->str($value))], ['op' => 'set_field', 'field' => $field, 'value' => $value]];
    }

    // ── Examples / test cases ────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $c
     * @param  array<string, mixed>  $op
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function exampleOp(?array $c, array $op): array
    {
        if ($c === null) {
            throw new OpError('an example change needs target_id (the example).');
        }
        if (($c['status'] ?? 'active') !== 'active') {
            throw new OpError('that example is already retired.');
        }

        if ($op['op'] === 'retire_example') {
            $c['status'] = 'retired';
            $reason = $this->nullableText($op['reason'] ?? null, 500);

            return [$c, [$this->entry('retire_example', 'reply', null, 'Retire example reply', (string) $c['reply'], null)], ['op' => 'retire_example', 'reason' => $reason]];
        }

        $reply = $this->text($op['reply'] ?? null, 8000);
        $old = (string) $c['reply'];
        $c['reply'] = $reply;
        if (filled($op['customer_message'] ?? null)) {
            $c['customer_message'] = $this->text($op['customer_message'], 8000);
        }
        $c['replaced'] = true;

        return [$c, [$this->entry('replace_example', 'reply', null, 'Replace example reply', $old, $reply)], ['op' => 'replace_example', 'reply' => $reply, 'customer_message' => $op['customer_message'] ?? null]];
    }

    /**
     * @param  array<string, mixed>|null  $c
     * @param  array<string, mixed>  $op
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function testCaseOp(?array $c, array $op, int $i): array
    {
        if ($c !== null) {
            throw new OpError('create_test_case is the only op of a test_case change.');
        }
        $frozen = (int) ($op['frozen_case_id'] ?? 0);
        if ($frozen <= 0) {
            throw new OpError('create_test_case needs frozen_case_id.');
        }
        $content = [
            'frozen_case_id' => $frozen,
            'name' => $this->text($op['name'] ?? null, 255),
            'playbook_id' => isset($op['playbook_id']) && (int) $op['playbook_id'] > 0 ? (int) $op['playbook_id'] : null,
            'playbook_slug' => filled($op['playbook_slug'] ?? null) ? (string) $op['playbook_slug'] : null,
            'expectation' => AgentTestCase::normalizeExpectation($op['expectation'] ?? []),
            'pinned' => (bool) ($op['pinned'] ?? false),
        ];
        $e = $content['expectation'];
        $summary = implode('; ', array_filter([
            $e['expected_outcome'] ? 'outcome: '.$e['expected_outcome'] : null,
            $e['must_include'] !== [] ? 'must include: '.implode(', ', $e['must_include']) : null,
            $e['must_not_include'] !== [] ? 'must not include: '.implode(', ', $e['must_not_include']) : null,
        ]));

        return [$content, [$this->entry('create_test_case', null, null, "Add test case \"{$content['name']}\"", null, $summary ?: null)], ['op' => 'create_test_case', ...$content]];
    }

    // ── Shared helpers ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $op
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function listItemOp(array $c, array $op, string $field): array
    {
        $id = (string) ($op['id'] ?? '');
        $index = $this->indexOf($c[$field] ?? [], $id);
        if ($index === null) {
            throw new OpError("no {$this->label($field)} with id \"{$id}\" in the live version.");
        }
        $old = (string) $c[$field][$index]['text'];

        if ($op['op'] === 'remove_item') {
            array_splice($c[$field], $index, 1);

            return [$c, [$this->entry('remove_item', $field, $id, 'Remove '.$this->label($field), $old, null)], ['op' => 'remove_item', 'field' => $field, 'id' => $id]];
        }

        $text = $this->text($op['text'] ?? null, 1000);
        $c[$field][$index] = ['id' => $id, 'text' => $text];

        return [$c, [$this->entry('replace_item', $field, $id, 'Change '.$this->label($field), $old, $text)], ['op' => 'replace_item', 'field' => $field, 'id' => $id, 'text' => $text]];
    }

    /** @return array<string, mixed> */
    private function entry(string $op, ?string $field, ?string $itemId, string $label, ?string $before, ?string $after): array
    {
        return ['op' => $op, 'field' => $field, 'item_id' => $itemId, 'label' => $label, 'before' => $before, 'after' => $after];
    }

    /** @param  array<string, mixed>  $rule */
    public function ruleText(array $rule): string
    {
        $if = trim((string) ($rule['if'] ?? ''));

        return ($if === '' || mb_strtolower($if) === 'always') ? (string) $rule['then'] : "When {$if}: {$rule['then']}";
    }

    private function label(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? str_replace('_', ' ', $field);
    }

    private function text(mixed $value, int $max): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '') {
            throw new OpError('text is required.');
        }
        if (mb_strlen($text) > $max) {
            throw new OpError("text is longer than {$max} characters.");
        }

        return $text;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->text($value, $max);
    }

    private function condition(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? 'always' : mb_substr($text, 0, 500);
    }

    private function maxWords(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (int) $value < 20 || (int) $value > 2000) {
            throw new OpError('max_words must be between 20 and 2000, or null.');
        }

        return (int) $value;
    }

    /** @return list<string> */
    private function requiredEvidence(mixed $value): array
    {
        $values = array_map('strval', is_array($value) ? $value : [$value]);
        $bad = array_diff(array_filter($values, fn ($v) => $v !== ''), ['order', 'tracking', 'photos']);
        if ($bad !== []) {
            throw new OpError('required_evidence accepts order, tracking, photos.');
        }

        return array_values(array_unique(array_filter($values, fn ($v) => $v !== '')));
    }

    private function kind(mixed $value): string
    {
        $kind = (string) $value;
        if (! in_array($kind, KnowledgeItem::KINDS, true) || $kind === 'kb_chunk') {
            throw new OpError('kind must be fact, policy or snippet.');
        }

        return $kind;
    }

    /** @return array{playbooks: list<string>, products: list<string>, countries: list<string>, global: bool} */
    private function scope(mixed $value): array
    {
        $scope = KnowledgeItem::normalizeScope($value);
        if (KnowledgeItem::scopeIsEmpty($scope)) {
            throw new OpError('knowledge needs a scope: global true, or the playbooks / products / countries it applies to.');
        }

        return $scope;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            throw new OpError('dates must be ISO 8601.');
        }
    }

    /** @param  array<string, mixed>  $c */
    private function checkWindow(array $c): void
    {
        if (! empty($c['valid_from']) && ! empty($c['valid_until']) && $c['valid_until'] < $c['valid_from']) {
            throw new OpError('valid_until is before valid_from.');
        }
    }

    /** @param  list<array<string, mixed>>  $items */
    private function newId(mixed $given, string $prefix, array $items, int $seed): string
    {
        $id = is_scalar($given) ? trim((string) $given) : '';
        $taken = array_map(fn ($item) => (string) ($item['id'] ?? ''), $items);
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id) === 1 && ! in_array($id, $taken, true)) {
            return $id;
        }

        return $prefix.'-'.Str::lower(Str::random(8));
    }

    /** @param  list<array<string, mixed>>  $items */
    private function indexOf(array $items, string $id): ?int
    {
        foreach (array_values($items) as $index => $item) {
            if ((string) ($item['id'] ?? '') === $id && $id !== '') {
                return $index;
            }
        }

        return null;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function findByText(array $items, string $text): ?string
    {
        foreach ($items as $item) {
            if ($this->same((string) ($item['text'] ?? ''), $text)) {
                return (string) $item['id'];
            }
        }

        return null;
    }

    private function same(string $a, string $b): bool
    {
        $norm = fn (string $s) => trim(preg_replace('/\s+/u', ' ', mb_strtolower(trim($s, " \t\n\r.!"))) ?? '');

        return $norm($a) === $norm($b);
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
