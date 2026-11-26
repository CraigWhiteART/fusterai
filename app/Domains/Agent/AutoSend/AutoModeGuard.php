<?php

namespace App\Domains\Agent\AutoSend;

use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\SimulationResult;
use App\Domains\Agent\Simulation\SimulationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Who may switch a playbook to `auto`, and what happens when an auto
 * playbook's instructions change.
 *
 * Preconditions (server-side; the playbook page shows the same checklist):
 *  - a live version, not archived, not always_human;
 *  - at least agent.auto_send.min_test_cases active test cases;
 *  - its most recent playbook test run, on the CURRENT live version,
 *    completed with a pass rate >= agent.auto_send.min_test_pass_rate;
 *  - an explicit confirmation in the request.
 *
 * When an auto playbook gets a new live version (approved proposal, revert,
 * manual edit or restore) — or the voice, or knowledge / examples in its
 * scope, change — auto-send is held (`auto_hold = retesting`) and its tests
 * re-run; pass = hold lifted, anything else = demoted to draft (notice +
 * activity log + admin notification).
 */
class AutoModeGuard
{
    public function __construct(
        private readonly CircuitBreaker $breakers,
    ) {}

    /**
     * @return array{ok: bool, items: list<array{key: string, label: string, ok: bool, detail: string, blocking: bool}>, latest_test_run: array<string, mixed>|null, min_test_cases: int, min_pass_rate: float}
     */
    public function preconditions(Playbook $playbook): array
    {
        $min = (int) config('agent.auto_send.min_test_cases', 5);
        $rate = (float) config('agent.auto_send.min_test_pass_rate', 0.9);
        $active = AgentTestCase::where('workspace_id', $playbook->workspace_id)
            ->where('playbook_id', $playbook->id)
            ->where('status', AgentTestCase::STATUS_ACTIVE)
            ->count();
        $latest = $this->latestTestRun($playbook);
        $onLive = $latest !== null && (int) (($latest->selection ?? [])['playbook_version_id'] ?? 0) === (int) $playbook->live_version_id;
        $score = $latest !== null ? $this->passRate($latest) : null;
        $settings = AgentSetting::forWorkspace((int) $playbook->workspace_id);

        $items = [
            ['key' => 'live_version', 'label' => 'Has a live version', 'ok' => $playbook->live_version_id !== null && $playbook->archived_at === null, 'detail' => $playbook->live_version_id !== null ? 'Live version '.($playbook->liveVersion->version ?? '?').'.' : 'Save a version first.', 'blocking' => true],
            ['key' => 'not_always_human', 'label' => 'Not marked "always a human"', 'ok' => ! $playbook->always_human, 'detail' => $playbook->always_human ? 'Turn off "always a human" first.' : 'OK.', 'blocking' => true],
            ['key' => 'test_cases', 'label' => "At least {$min} active test cases", 'ok' => $active >= $min, 'detail' => "{$active} active test case(s).", 'blocking' => true],
            ['key' => 'tests_on_live', 'label' => 'Latest test run is on the current live version', 'ok' => $onLive && $latest->status === Simulation::STATUS_COMPLETED, 'detail' => match (true) {
                $latest === null => 'No test run yet: run the playbook tests.',
                ! $onLive => 'The latest test run was on an older version: run the tests again.',
                $latest->status !== Simulation::STATUS_COMPLETED => "The latest test run is {$latest->status}.",
                default => 'Run #'.$latest->id.' finished '.($latest->finished_at?->diffForHumans() ?? '').'.',
            }, 'blocking' => true],
            ['key' => 'pass_rate', 'label' => 'Tests pass at '.round($rate * 100).'% or better', 'ok' => $onLive && $score !== null && $score['rate'] >= $rate && $score['cases'] >= $min, 'detail' => $score !== null ? "{$score['passed']} of {$score['cases']} passed (".round($score['rate'] * 100).'%).' : 'No results.', 'blocking' => true],
            ['key' => 'workspace_live', 'label' => 'Workspace is in live mode', 'ok' => $settings->mode === AgentSetting::MODE_LIVE, 'detail' => $settings->mode === AgentSetting::MODE_LIVE ? 'OK.' : "The workspace is in {$settings->mode} mode: nothing is auto-sent until it is live.", 'blocking' => false],
        ];

        return [
            'ok' => collect($items)->every(fn ($i) => $i['ok'] || ! $i['blocking']),
            'items' => $items,
            'latest_test_run' => $latest !== null ? [
                'id' => $latest->id,
                'status' => $latest->status,
                'playbook_version_id' => ($latest->selection ?? [])['playbook_version_id'] ?? null,
                'on_live_version' => $onLive,
                'pass_rate' => $score['rate'] ?? null,
                'passed' => $score['passed'] ?? null,
                'cases' => $score['cases'] ?? null,
                'finished_at' => $latest->finished_at?->toIso8601String(),
            ] : null,
            'min_test_cases' => $min,
            'min_pass_rate' => $rate,
        ];
    }

    /** @throws ValidationException */
    public function enable(Playbook $playbook, int $userId, bool $confirmed): void
    {
        if ($playbook->mode === Playbook::MODE_AUTO) {
            return;
        }
        if (! $confirmed) {
            throw ValidationException::withMessages(['confirm_auto' => 'Confirm that replies for this playbook may be sent to customers without review.']);
        }
        $check = $this->preconditions($playbook);
        if (! $check['ok']) {
            $failed = collect($check['items'])->filter(fn ($i) => $i['blocking'] && ! $i['ok'])->map(fn ($i) => $i['label'].': '.$i['detail'])->values()->all();
            throw ValidationException::withMessages(['mode' => ['Auto-send cannot be enabled yet. '.implode(' ', $failed)]]);
        }

        $playbook->forceFill([
            'mode' => Playbook::MODE_AUTO,
            'auto_enabled_at' => now(),
            'auto_enabled_by' => $userId,
            'auto_hold' => null,
            'auto_hold_simulation_id' => null,
        ])->save();

        AutoSendAudit::record('playbook_auto_enabled', "Playbook \"{$playbook->name}\" switched to auto-send at {$playbook->rollout_percent}% rollout", $playbook, null, [
            'playbook_id' => $playbook->id,
            'rollout_percent' => $playbook->rollout_percent,
            'test_run_id' => $check['latest_test_run']['id'] ?? null,
            'pass_rate' => $check['latest_test_run']['pass_rate'] ?? null,
        ], $userId);
    }

    /**
     * Post-approve step of ProposalService::approve (and revert). Every auto
     * playbook the proposal can change a reply for is held and re-tested:
     * its own new versions, all of them for a voice change, and those in
     * scope of the knowledge / examples it wrote or retired. Never throws.
     */
    public function afterProposalApplied(Proposal $proposal): void
    {
        try {
            $applied = (array) ($proposal->applied ?? []);
            $ws = (int) $proposal->workspace_id;
            $ids = array_map(fn ($p) => (int) ($p['playbook_id'] ?? 0), (array) ($applied['playbooks'] ?? []));
            $all = ($applied['voice'] ?? null) !== null;

            $knowledgeIds = [...(array) ($applied['knowledge_created'] ?? []), ...(array) ($applied['knowledge_retired'] ?? [])];
            foreach ((array) ($applied['knowledge_superseded'] ?? []) as $pair) {
                $knowledgeIds[] = (int) ($pair['old_id'] ?? 0);
                $knowledgeIds[] = (int) ($pair['new_id'] ?? 0);
            }
            $slugs = [];
            if (! $all && array_filter($knowledgeIds) !== []) {
                foreach (KnowledgeItem::where('workspace_id', $ws)->whereIn('id', array_filter($knowledgeIds))->get(['id', 'scope']) as $item) {
                    $scoped = self::scopedSlugs($item->scope);
                    if ($scoped === null) {
                        $all = true;
                        break;
                    }
                    $slugs = [...$slugs, ...$scoped];
                }
            }
            $exampleIds = [...(array) ($applied['examples_created'] ?? []), ...(array) ($applied['examples_retired'] ?? [])];
            if (! $all && $exampleIds !== []) {
                foreach (AgentExample::where('workspace_id', $ws)->whereIn('id', $exampleIds)->pluck('playbook_slug') as $slug) {
                    if ($slug === null || $slug === '') {
                        $all = true;
                        break;
                    }
                    $slugs[] = (string) $slug;
                }
            }

            $this->holdAffected($ws, $all, $slugs, array_filter($ids), "proposal #{$proposal->id}");
        } catch (Throwable $e) {
            Log::error('Auto-mode retest after a proposal failed to start', ['proposal_id' => $proposal->id, 'error' => $e->getMessage()]);
        }
    }

    /** The live voice changed (save or restore): every auto playbook re-tests. Never throws. */
    public function afterVoiceChanged(int $workspaceId, string $cause): void
    {
        try {
            $this->holdAffected($workspaceId, true, [], [], $cause);
        } catch (Throwable $e) {
            Log::error('Auto-mode retest after a voice change failed to start', ['workspace_id' => $workspaceId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Knowledge was created, edited or retired directly. Pass every scope
     * the item had before and after the change. Never throws.
     *
     * @param  list<mixed>  $scopes
     */
    public function afterKnowledgeChanged(int $workspaceId, array $scopes, string $cause): void
    {
        try {
            $all = false;
            $slugs = [];
            foreach ($scopes as $scope) {
                $scoped = self::scopedSlugs($scope);
                if ($scoped === null) {
                    $all = true;
                    break;
                }
                $slugs = [...$slugs, ...$scoped];
            }
            $this->holdAffected($workspaceId, $all, $slugs, [], $cause);
        } catch (Throwable $e) {
            Log::error('Auto-mode retest after a knowledge change failed to start', ['workspace_id' => $workspaceId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Playbook slugs a knowledge scope is limited to, or null when it can
     * reach any playbook (global, or limited only by product / country).
     *
     * @return list<string>|null
     */
    public static function scopedSlugs(mixed $scope): ?array
    {
        $scope = KnowledgeItem::normalizeScope($scope);

        return $scope['global'] || $scope['playbooks'] === [] ? null : $scope['playbooks'];
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<int, int>  $ids
     */
    private function holdAffected(int $workspaceId, bool $all, array $slugs, array $ids, string $cause): void
    {
        if (! $all && $slugs === [] && $ids === []) {
            return;
        }
        $query = Playbook::where('workspace_id', $workspaceId)->where('mode', Playbook::MODE_AUTO)->whereNull('archived_at');
        if (! $all) {
            $query->where(fn ($q) => $q->whereIn('id', $ids === [] ? [0] : array_values($ids))->orWhereIn('slug', $slugs === [] ? [''] : array_values(array_unique($slugs))));
        }
        foreach ($query->orderBy('id')->get() as $playbook) {
            $this->afterVersionChanged($playbook, $cause);
        }
    }

    /** An auto playbook has a new live version: hold auto-send and re-run its tests. */
    public function afterVersionChanged(Playbook $playbook, string $cause): void
    {
        $playbook->refresh();
        if ($playbook->mode !== Playbook::MODE_AUTO) {
            return;
        }

        $previousId = $playbook->auto_hold_simulation_id;
        $playbook->forceFill(['auto_hold' => Playbook::HOLD_RETESTING, 'auto_hold_simulation_id' => null])->save();
        AutoSendAudit::record('playbook_retest_started', "Playbook \"{$playbook->name}\" changed ({$cause}); auto-send is on hold while its tests re-run", $playbook, null, [
            'playbook_id' => $playbook->id,
            'live_version_id' => $playbook->live_version_id,
            'cause' => $cause,
        ]);

        try {
            $simulation = app(SimulationService::class)->createPlaybookTests($playbook, null);
        } catch (Throwable $e) {
            Log::error('Could not start the playbook retest', ['playbook_id' => $playbook->id, 'error' => $e->getMessage()]);
            $this->breakers->demote($playbook, 'retest', "Its tests could not be re-run after a change ({$cause}).", [], 'playbook_demoted');

            return;
        }

        $playbook->refresh();
        if ($playbook->auto_hold === Playbook::HOLD_RETESTING && $playbook->auto_hold_simulation_id === null) {
            $playbook->forceFill(['auto_hold_simulation_id' => $simulation->id])->save();
        }
        // A re-test still running for the previous change is moot. Cancelled
        // only now that the hold points at the new run, so its finish is ignored.
        $previous = $previousId !== null && (int) $previousId !== (int) $simulation->id ? Simulation::find($previousId) : null;
        if ($previous !== null && ! $previous->isFinished()) {
            try {
                app(SimulationService::class)->cancel($previous);
            } catch (Throwable $e) {
                Log::warning('Could not cancel the superseded playbook retest', ['simulation_id' => $previous->id, 'error' => $e->getMessage()]);
            }
        }
        // No cases (finished at once) or a synchronous queue: decide now.
        if ($simulation->fresh()?->isFinished()) {
            $this->afterSimulationFinished($simulation->fresh());
        }
    }

    /** Hook from SimulationRunner::finish (and above). Never throws. */
    public function afterSimulationFinished(?Simulation $simulation): void
    {
        if ($simulation === null || $simulation->kind !== Simulation::KIND_PLAYBOOK_TESTS || $simulation->playbook_id === null) {
            return;
        }

        try {
            $playbook = Playbook::find($simulation->playbook_id);
            if ($playbook === null || $playbook->mode !== Playbook::MODE_AUTO || $playbook->auto_hold !== Playbook::HOLD_RETESTING) {
                return;
            }
            $forVersion = (int) (($simulation->selection ?? [])['playbook_version_id'] ?? 0);
            if ($forVersion !== (int) $playbook->live_version_id) {
                return; // a run for an older version; the current one decides
            }
            // A voice / knowledge / example change keeps the version but
            // starts a new re-test: only the run the hold points at decides.
            if ($playbook->auto_hold_simulation_id !== null && (int) $playbook->auto_hold_simulation_id !== (int) $simulation->id) {
                return;
            }

            $min = (int) config('agent.auto_send.min_test_cases', 5);
            $rate = (float) config('agent.auto_send.min_test_pass_rate', 0.9);
            $score = $this->passRate($simulation);
            $passed = $simulation->status === Simulation::STATUS_COMPLETED && $score['cases'] >= $min && $score['rate'] >= $rate;

            if ($passed) {
                $playbook->forceFill(['auto_hold' => null, 'auto_hold_simulation_id' => null])->save();
                AutoSendAudit::record('playbook_retest_passed', "Playbook \"{$playbook->name}\" passed its re-test ({$score['passed']}/{$score['cases']}); auto-send continues", $playbook, null, [
                    'playbook_id' => $playbook->id, 'simulation_id' => $simulation->id, 'pass_rate' => $score['rate'],
                ]);

                return;
            }

            $this->breakers->demote($playbook, 'retest', "Its tests regressed after a change: {$score['passed']} of {$score['cases']} passed (needs ".round($rate * 100)."% of at least {$min}; run #{$simulation->id} {$simulation->status}).", [], 'playbook_demoted');
        } catch (Throwable $e) {
            Log::error('Auto-mode retest evaluation failed', ['simulation_id' => $simulation->id, 'error' => $e->getMessage()]);
        }
    }

    private function latestTestRun(Playbook $playbook): ?Simulation
    {
        return Simulation::where('workspace_id', $playbook->workspace_id)
            ->where('kind', Simulation::KIND_PLAYBOOK_TESTS)
            ->where('playbook_id', $playbook->id)
            ->latest('id')
            ->first();
    }

    /** @return array{cases: int, passed: int, rate: float} effective verdicts (human overrides count) */
    private function passRate(Simulation $simulation): array
    {
        $cases = count($simulation->cases ?? []);
        $passed = SimulationResult::where('simulation_id', $simulation->id)->get()
            ->filter(fn (SimulationResult $r) => in_array($r->effectiveVerdict(), [SimulationResult::VERDICT_PASS, SimulationResult::VERDICT_IMPROVED], true))
            ->count();

        return ['cases' => $cases, 'passed' => $passed, 'rate' => $cases > 0 ? $passed / $cases : 0.0];
    }
}
