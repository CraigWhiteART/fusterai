<?php

namespace App\Domains\Agent\Proposals;

use App\Ai\EmbeddingService;
use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\PlaybookVersion;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\ProposalChange;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Services\KbKnowledgeSync;
use App\Domains\Agent\Services\VersionService;
use App\Domains\Agent\Simulation\FrozenCaseFactory;
use App\Domains\Agent\Simulation\TestCaseService;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes an approved proposal (and undoes it). Everything happens in one
 * transaction: new immutable versions through VersionService (which flips
 * the live pointers), knowledge / example rows, test cases, the proposal's
 * `applied` record and the feedback-event link. `applied` lists exactly
 * what was written so revert can reverse it.
 */
class ProposalApplier
{
    public function __construct(
        private readonly VersionService $versions,
        private readonly FrozenCaseFactory $frozen,
        private readonly TestCaseService $testCases,
    ) {}

    public function apply(Proposal $proposal, int $userId, ?string $note = null): Proposal
    {
        $embed = [];

        $result = DB::transaction(function () use ($proposal, $userId, $note, &$embed) {
            /** @var Proposal $locked */
            $locked = Proposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new ProposalStateException("This proposal is {$locked->status}.", 'not_open');
            }

            $ws = $locked->workspace_id;
            $changeNote = mb_substr("Proposal #{$locked->id}: {$locked->title}", 0, 500);
            $applied = [
                'voice' => null, 'playbooks' => [], 'playbooks_created' => [],
                'knowledge_created' => [], 'knowledge_superseded' => [], 'knowledge_retired' => [],
                'examples_retired' => [], 'examples_created' => [], 'test_cases_created' => [],
                // What the reviewer left out (partial approval); nothing of it was written.
                'excluded' => array_values((array) ($locked->excluded ?? [])),
            ];
            $slugIds = Playbook::where('workspace_id', $ws)->pluck('id', 'slug')->all();
            $dontPhrases = [];

            foreach ($locked->changes()->get() as $change) {
                $after = (array) $change->after;

                switch ($change->target_type) {
                    case ProposalChange::TARGET_VOICE:
                        $from = AgentSetting::forWorkspace($ws)->live_voice_version_id;
                        $voice = $this->versions->createVoiceVersion($ws, $after, $userId, $changeNote);
                        $applied['voice'] = ['from_version_id' => $from, 'to_version_id' => $voice->id];
                        foreach ($change->ops as $op) {
                            if (($op['op'] ?? null) === 'add_dont') {
                                $dontPhrases[] = (string) $op['text'];
                            }
                        }
                        break;

                    case ProposalChange::TARGET_PLAYBOOK:
                        if ($change->target_id === null) {
                            $playbook = $this->versions->createPlaybook($ws, (string) $after['slug'], [
                                'name' => $after['name'] ?? $after['slug'],
                                'mode' => Playbook::MODE_DRAFT,
                                'rollout_percent' => 0,
                            ], [...$after, 'allowed_actions' => []], $userId, $changeNote);
                            $slugIds[$playbook->slug] = $playbook->id;
                            $applied['playbooks_created'][] = $playbook->id;
                            break;
                        }
                        $playbook = Playbook::where('workspace_id', $ws)->lockForUpdate()->findOrFail($change->target_id);
                        $live = PlaybookVersion::findOrFail($playbook->live_version_id);
                        // Permissions are never changed by a proposal: keep the live allowed_actions.
                        $version = $this->versions->createPlaybookVersion($playbook, [...$after, 'allowed_actions' => $live->allowed_actions ?? []], $userId, $changeNote);
                        $applied['playbooks'][] = ['playbook_id' => $playbook->id, 'from_version_id' => $live->id, 'to_version_id' => $version->id];
                        break;

                    case ProposalChange::TARGET_KNOWLEDGE:
                        $this->applyKnowledge($ws, $change, $after, $userId, $applied, $embed);
                        break;

                    case ProposalChange::TARGET_EXAMPLE:
                        $example = AgentExample::where('workspace_id', $ws)->lockForUpdate()->findOrFail($change->target_id);
                        if (! empty($after['replaced'])) {
                            $copy = AgentExample::create([
                                'workspace_id' => $ws,
                                'conversation_id' => $example->conversation_id,
                                'thread_id' => $example->thread_id,
                                'customer_message' => (string) ($after['customer_message'] ?? $example->customer_message),
                                'reply' => (string) $after['reply'],
                                'playbook_slug' => $example->playbook_slug,
                                'sentiment' => $example->sentiment,
                                'country' => $example->country,
                                'tags' => $example->tags,
                                'source' => 'proposal',
                                'status' => 'active',
                                'created_by' => $userId,
                            ]);
                            $applied['examples_created'][] = $copy->id;
                        }
                        $example->forceFill(['status' => 'retired'])->save();
                        $applied['examples_retired'][] = $example->id;
                        break;

                    case ProposalChange::TARGET_TEST_CASE:
                        $case = FrozenCase::where('workspace_id', $ws)->findOrFail((int) $after['frozen_case_id']);
                        $playbookId = $after['playbook_id'] ?? (isset($after['playbook_slug']) ? ($slugIds[$after['playbook_slug']] ?? null) : null);
                        $tc = $this->testCases->create(
                            $case, (string) $after['name'], (array) $after['expectation'], $playbookId !== null ? (int) $playbookId : null,
                            (bool) ($after['pinned'] ?? false), $this->testSource($locked->origin), AgentTestCase::STATUS_ACTIVE, $userId, $locked->id,
                        );
                        $applied['test_cases_created'][] = $tc->id;
                        break;
                }
            }

            if (($tc = $this->feedbackTestCase($locked, $userId, $dontPhrases)) !== null) {
                $applied['test_cases_created'][] = $tc->id;
            }

            $locked->forceFill([
                'status' => Proposal::STATUS_APPLIED,
                'applied' => $applied,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'applied_at' => now(),
                'review_note' => $note,
            ])->save();

            if ($locked->feedback_event_id !== null) {
                $event = FeedbackEvent::whereKey($locked->feedback_event_id)->lockForUpdate()->first();
                $event?->forceFill(['meta' => [...($event->meta ?? []), 'proposal_id' => $locked->id, 'proposal_status' => Proposal::STATUS_APPLIED]])->save();
            }

            return $locked;
        });

        $this->embed($embed);

        return $result->fresh('changes') ?? $result;
    }

    public function revert(Proposal $proposal, int $userId): Proposal
    {
        return DB::transaction(function () use ($proposal, $userId) {
            /** @var Proposal $locked */
            $locked = Proposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Proposal::STATUS_APPLIED) {
                throw new ProposalStateException('Only an applied proposal can be reverted.', 'not_applied');
            }
            $ws = $locked->workspace_id;
            $a = (array) $locked->applied;
            $note = "Reverted proposal #{$locked->id}";

            // Check every pointer first: never half-revert.
            if (($a['voice'] ?? null) !== null) {
                if (AgentSetting::forWorkspace($ws)->live_voice_version_id !== (int) $a['voice']['to_version_id']) {
                    throw new ProposalStateException('The voice changed again after this proposal; restore an older voice version from the Voice page instead.', 'moved', ['target' => 'voice']);
                }
                if ($a['voice']['from_version_id'] === null) {
                    throw new ProposalStateException('There is no earlier voice version to restore.', 'moved', ['target' => 'voice']);
                }
            }
            foreach ((array) ($a['playbooks'] ?? []) as $p) {
                $playbook = Playbook::where('workspace_id', $ws)->find($p['playbook_id']);
                if ($playbook === null || (int) $playbook->live_version_id !== (int) $p['to_version_id']) {
                    throw new ProposalStateException('A playbook changed again after this proposal; restore an older version from the playbook page instead.', 'moved', ['target' => 'playbook', 'playbook_id' => $p['playbook_id']]);
                }
            }

            $restored = ['voice' => null, 'playbooks' => []];
            if (($a['voice'] ?? null) !== null) {
                $old = VoiceVersion::where('workspace_id', $ws)->findOrFail((int) $a['voice']['from_version_id']);
                $restored['voice'] = $this->versions->createVoiceVersion($ws, $old->toContentArray(), $userId, $note, $old->id)->id;
            }
            foreach ((array) ($a['playbooks'] ?? []) as $p) {
                $playbook = Playbook::where('workspace_id', $ws)->findOrFail($p['playbook_id']);
                $old = PlaybookVersion::where('playbook_id', $playbook->id)->findOrFail((int) $p['from_version_id']);
                $restored['playbooks'][] = $this->versions->createPlaybookVersion($playbook, $old->toContentArray(), $userId, $note, $old->id)->id;
            }
            Playbook::where('workspace_id', $ws)->whereIn('id', (array) ($a['playbooks_created'] ?? []))->update(['archived_at' => now()]);

            KnowledgeItem::where('workspace_id', $ws)->whereIn('id', (array) ($a['knowledge_created'] ?? []))->update(['status' => KnowledgeItem::STATUS_RETIRED]);
            KnowledgeItem::where('workspace_id', $ws)->whereIn('id', (array) ($a['knowledge_retired'] ?? []))->update(['status' => KnowledgeItem::STATUS_ACTIVE]);
            foreach ((array) ($a['knowledge_superseded'] ?? []) as $pair) {
                KnowledgeItem::where('workspace_id', $ws)->whereKey($pair['new_id'])->update(['status' => KnowledgeItem::STATUS_RETIRED]);
                KnowledgeItem::where('workspace_id', $ws)->whereKey($pair['old_id'])->update(['status' => KnowledgeItem::STATUS_ACTIVE]);
            }
            AgentExample::where('workspace_id', $ws)->whereIn('id', (array) ($a['examples_retired'] ?? []))->update(['status' => 'active']);
            AgentExample::where('workspace_id', $ws)->whereIn('id', (array) ($a['examples_created'] ?? []))->update(['status' => 'retired']);
            AgentTestCase::where('workspace_id', $ws)->whereIn('id', (array) ($a['test_cases_created'] ?? []))->update(['status' => AgentTestCase::STATUS_ARCHIVED]);

            $locked->forceFill([
                'status' => Proposal::STATUS_REVERTED,
                'reverted_by' => $userId,
                'reverted_at' => now(),
                'applied' => [...$a, 'restored' => $restored],
            ])->save();

            return $locked->fresh('changes') ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $applied
     * @param  list<KnowledgeItem>  $embed
     */
    private function applyKnowledge(int $ws, ProposalChange $change, array $after, int $userId, array &$applied, array &$embed): void
    {
        $fields = fn (array $a) => [
            'workspace_id' => $ws,
            'kind' => (string) $a['kind'],
            'title' => $a['title'] ?? null,
            'body' => (string) $a['body'],
            'scope' => KnowledgeItem::normalizeScope($a['scope'] ?? []),
            'valid_from' => $a['valid_from'] ?? null,
            'valid_until' => $a['valid_until'] ?? null,
            'status' => KnowledgeItem::STATUS_ACTIVE,
            'source_type' => 'proposal',
            'content_hash' => hash('sha256', ($a['title'] ?? '')."\n".$a['body']),
            'created_by' => $userId,
        ];

        if ($change->target_id === null) {
            $item = KnowledgeItem::create($fields($after));
            $applied['knowledge_created'][] = $item->id;
            $embed[] = $item;

            return;
        }

        $old = KnowledgeItem::where('workspace_id', $ws)->lockForUpdate()->findOrFail($change->target_id);
        if (($after['status'] ?? null) === KnowledgeItem::STATUS_RETIRED) {
            $old->forceFill(['status' => KnowledgeItem::STATUS_RETIRED])->save();
            $applied['knowledge_retired'][] = $old->id;

            return;
        }

        // An edit is a new item that supersedes the old one, so revert can bring the old one back.
        $new = KnowledgeItem::create([...$fields($after), 'supersedes_id' => $old->id]);
        $old->forceFill(['status' => KnowledgeItem::STATUS_RETIRED])->save();
        $applied['knowledge_superseded'][] = ['old_id' => $old->id, 'new_id' => $new->id];
        $embed[] = $new;
    }

    /**
     * Every approved feedback proposal saves its conversation as a test case:
     * frozen at the customer message the draft answered, expecting a draft
     * that avoids what the human objected to.
     *
     * @param  list<string>  $dontPhrases
     */
    private function feedbackTestCase(Proposal $proposal, int $userId, array $dontPhrases): ?AgentTestCase
    {
        if ($proposal->origin !== Proposal::ORIGIN_FEEDBACK || $proposal->run_id === null) {
            return null;
        }
        $run = AgentRun::find($proposal->run_id);
        $conversation = $proposal->conversation_id !== null ? Conversation::find($proposal->conversation_id) : null;
        $cut = $run?->customer_thread_id !== null ? Thread::find($run->customer_thread_id) : null;
        if ($run === null || $conversation === null || $cut === null) {
            return null;
        }

        try {
            $case = $this->frozen->fromConversation($conversation, $cut, 'feedback', ['proposal_id' => $proposal->id, 'run_id' => $run->id]);
        } catch (\InvalidArgumentException $e) {
            // The answered message has no readable text any more: approve without the test case.
            Log::info('Feedback test case skipped', ['proposal_id' => $proposal->id, 'reason' => $e->getMessage()]);

            return null;
        }
        $expectation = (array) ($proposal->evidence['test_expectation'] ?? []);
        $expectation['must_not_include'] = [...(array) ($expectation['must_not_include'] ?? []), ...$dontPhrases];
        $expectation['expected_outcome'] ??= 'draft';
        $expectation['notes'] ??= $proposal->evidence['comment'] ?? $proposal->title;

        return $this->testCases->create(
            $case,
            'Feedback: '.mb_substr($proposal->title, 0, 200),
            $expectation,
            $run->playbook_id,
            false,
            'feedback',
            AgentTestCase::STATUS_ACTIVE,
            $userId,
            $proposal->id,
            $proposal->feedback_event_id,
        );
    }

    private function testSource(string $origin): string
    {
        return match ($origin) {
            Proposal::ORIGIN_FEEDBACK => 'feedback',
            Proposal::ORIGIN_MINING => 'mining',
            Proposal::ORIGIN_QA => 'qa',
            default => 'manual',
        };
    }

    /** @param  list<KnowledgeItem>  $items */
    private function embed(array $items): void
    {
        if ($items === []) {
            return;
        }
        try {
            if (app(EmbeddingService::class)->isConfigured($items[0]->workspace_id)) {
                app(KbKnowledgeSync::class)->embed($items[0]->workspace_id, $items);
            }
        } catch (Throwable $e) {
            // Keyword search still finds them; editing the item retries the embedding.
            Log::warning('Proposal knowledge embedding failed', ['error' => $e->getMessage()]);
        }
    }
}
