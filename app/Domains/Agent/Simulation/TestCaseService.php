<?php

namespace App\Domains\Agent\Simulation;

use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\Playbook;
use InvalidArgumentException;

/**
 * Test cases: a frozen case plus expectations. Draft cases (e.g. from
 * mining, not yet approved) are stored but never run by gates.
 */
class TestCaseService
{
    /**
     * @param  array<string, mixed>  $expectation  {must_include[], must_not_include[], expected_outcome, expected_handover_reason, expected_actions[], notes}
     * @param  string  $source  feedback | mining | manual | qa
     * @param  string  $status  draft | active | archived
     *
     * @throws InvalidArgumentException playbook from another workspace, unknown source / status
     */
    public function create(
        FrozenCase $case,
        string $name,
        array $expectation = [],
        ?int $playbookId = null,
        bool $pinned = false,
        string $source = 'manual',
        string $status = AgentTestCase::STATUS_ACTIVE,
        ?int $createdBy = null,
        ?int $proposalId = null,
        ?int $feedbackEventId = null,
    ): AgentTestCase {
        $this->assertPlaybook($case->workspace_id, $playbookId);
        if (! in_array($source, AgentTestCase::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown test case source [{$source}].");
        }
        if (! in_array($status, AgentTestCase::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown test case status [{$status}].");
        }

        return AgentTestCase::create([
            'workspace_id' => $case->workspace_id,
            'playbook_id' => $playbookId,
            'frozen_case_id' => $case->id,
            'name' => mb_substr(trim($name) !== '' ? trim($name) : 'Case #'.$case->id, 0, 255),
            'expectation' => AgentTestCase::normalizeExpectation($expectation),
            'pinned' => $pinned,
            'source' => $source,
            'status' => $status,
            'proposal_id' => $proposalId,
            'feedback_event_id' => $feedbackEventId,
            'created_by' => $createdBy,
        ])->load(['playbook', 'frozenCase']);
    }

    /**
     * @param  array<string, mixed>  $data  name?, expectation?, pinned?, status?, playbook_id?
     */
    public function update(AgentTestCase $testCase, array $data): AgentTestCase
    {
        if (array_key_exists('playbook_id', $data)) {
            $playbookId = $data['playbook_id'] !== null ? (int) $data['playbook_id'] : null;
            $this->assertPlaybook($testCase->workspace_id, $playbookId);
            $testCase->playbook_id = $playbookId;
        }
        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $testCase->name = mb_substr(trim((string) $data['name']), 0, 255);
        }
        if (array_key_exists('expectation', $data)) {
            $testCase->expectation = AgentTestCase::normalizeExpectation($data['expectation']);
        }
        if (array_key_exists('pinned', $data)) {
            $testCase->pinned = (bool) $data['pinned'];
        }
        if (isset($data['status'])) {
            if (! in_array($data['status'], AgentTestCase::STATUSES, true)) {
                throw new InvalidArgumentException("Unknown test case status [{$data['status']}].");
            }
            $testCase->status = (string) $data['status'];
        }
        $testCase->save();

        return $testCase->load(['playbook', 'frozenCase']);
    }

    public function archive(AgentTestCase $testCase): AgentTestCase
    {
        return $this->update($testCase, ['status' => AgentTestCase::STATUS_ARCHIVED]);
    }

    private function assertPlaybook(int $workspaceId, ?int $playbookId): void
    {
        if ($playbookId !== null && ! Playbook::where('workspace_id', $workspaceId)->whereKey($playbookId)->exists()) {
            throw new InvalidArgumentException("Playbook {$playbookId} is not in this workspace.");
        }
    }
}
