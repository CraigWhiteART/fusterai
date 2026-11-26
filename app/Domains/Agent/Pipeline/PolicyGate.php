<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use Throwable;

/**
 * Step 7: pure decision function: draft | auto_send | handover.
 *
 * Handover first (anything that means "a human must look": kill switch,
 * triage failure, no playbook, risk flags, always_human, playbook off,
 * missing evidence, writer handover, validator failure, empty draft).
 *
 * Otherwise the reply is a draft, unless EVERY auto-send condition holds
 * (autoSendBlockers() returns nothing). Each blocker is reported, so the run
 * records exactly why a reply was not auto-sent. Missing inputs fail
 * closed (missing key = blocked), and any exception means draft.
 *
 * Rewrite decision: a reply that needed the validator's one rewrite is
 * never auto-sent (blocker `rewritten`). The first draft had a problem the
 * validator caught; the rewrite is a single structured call checked once
 * more, so it has had less scrutiny than a first-pass clean draft. It stays
 * a draft for a human.
 */
final class PolicyGate
{
    public const DRAFT = 'draft';

    public const HANDOVER = 'handover';

    public const AUTO_SEND = 'auto_send';

    /** Sentiments that never get an unattended reply. */
    public const NO_AUTO_SENTIMENTS = ['angry', 'frustrated'];

    /**
     * Blockers that only say "this is not a live run in a live workspace";
     * the shadow report treats a run with no other blocker as one that would
     * have auto-sent.
     */
    public const SHADOW_IGNORED_BLOCKERS = ['agent_mode', 'not_live'];

    /** ...and additionally ignoring the playbook's own switch and rollout. */
    public const CANDIDATE_IGNORED_BLOCKERS = ['agent_mode', 'not_live', 'playbook_mode', 'rollout', 'playbook_hold'];

    /**
     * Stable 0-99 bucket per conversation, so a rollout percentage always
     * selects the same conversations: a conversation never changes bucket,
     * and raising / lowering the percentage only adds / removes the buckets
     * at the margin (bucket < percent).
     */
    public static function rolloutBucket(int|string|null $conversationKey): int
    {
        return (int) (crc32('agent-rollout:'.($conversationKey ?? '')) % 100);
    }

    public static function inRollout(int $bucket, int $percent): bool
    {
        return $bucket < max(0, min(100, $percent));
    }

    /**
     * @param  array{
     *     agent_mode: string,
     *     kill_switch: bool,
     *     triage_failed?: bool,
     *     playbook: array{mode: string, rollout_percent: int, always_human: bool}|null,
     *     rollout_bucket: int,
     *     risk_flags: list<string>,
     *     writer_handover: string|null,
     *     order_withheld: bool,
     *     missing_evidence: list<string>,
     *     validator_passed: bool|null,
     *     validator_error: bool,
     *     has_draft: bool,
     *     proposed_actions: list<array<string, mixed>>,
     *     auto_send?: array<string, mixed>|null,
     * }  $in
     * @return array{decision: string, reason: string|null, auto_send_eligible: bool, auto_send_blockers: list<string>}
     */
    public static function decide(array $in): array
    {
        $handover = fn (string $reason) => ['decision' => self::HANDOVER, 'reason' => $reason, 'auto_send_eligible' => false, 'auto_send_blockers' => ['handover']];

        if ($in['kill_switch']) {
            return $handover('kill_switch');
        }
        if ($in['triage_failed'] ?? false) {
            return $handover('triage_error');
        }
        if ($in['playbook'] === null) {
            return $handover('no_instruction');
        }
        if ($in['risk_flags'] !== []) {
            return $handover('risk:'.$in['risk_flags'][0]);
        }
        if ($in['playbook']['always_human']) {
            return $handover('always_human');
        }
        if ($in['playbook']['mode'] === Playbook::MODE_OFF) {
            return $handover('playbook_off');
        }
        if ($in['order_withheld']) {
            return $handover('order_email_mismatch');
        }
        if ($in['missing_evidence'] !== []) {
            return $handover('missing_evidence:'.implode(',', $in['missing_evidence']));
        }
        if ($in['writer_handover'] !== null) {
            return $handover('writer:'.$in['writer_handover']);
        }
        if ($in['validator_error']) {
            return $handover('validator_error');
        }
        if ($in['validator_passed'] !== true) {
            return $handover('validation_failed');
        }
        if (! $in['has_draft']) {
            return $handover('no_draft');
        }

        try {
            $blockers = self::autoSendBlockers($in);
        } catch (Throwable) {
            $blockers = ['gate_error'];
        }
        $eligible = $blockers === [];

        return [
            'decision' => $eligible ? self::AUTO_SEND : self::DRAFT,
            'reason' => null,
            'auto_send_eligible' => $eligible,
            'auto_send_blockers' => $blockers,
        ];
    }

    /**
     * Every condition auto-send needs, as the list of those that fail.
     * Empty = auto-send. The `auto_send` context is computed by the pipeline
     * (AutoSendContext); a missing context or key blocks.
     *
     * @param  array<string, mixed>  $in
     * @return list<string>
     */
    public static function autoSendBlockers(array $in): array
    {
        $a = $in['auto_send'] ?? null;
        if (! is_array($a)) {
            return ['no_context'];
        }

        $blocked = [];
        $check = function (bool $ok, string $blocker) use (&$blocked): void {
            if (! $ok) {
                $blocked[] = $blocker;
            }
        };

        $playbook = is_array($in['playbook'] ?? null) ? $in['playbook'] : null;
        $language = mb_strtolower(trim((string) ($a['language'] ?? '')));
        $allowedLanguages = array_map(fn ($l) => mb_strtolower((string) $l), (array) ($a['allowed_languages'] ?? []));

        $check(($a['context_error'] ?? true) === false, 'context_error');
        $check(($in['kill_switch'] ?? true) === false, 'kill_switch');
        $check(($in['agent_mode'] ?? null) === AgentSetting::MODE_LIVE, 'agent_mode');
        $check(($a['purpose_live'] ?? false) === true, 'not_live');
        $check(($a['paused'] ?? true) === false, 'paused');
        $check(($playbook['mode'] ?? null) === Playbook::MODE_AUTO, 'playbook_mode');
        $check(($playbook['always_human'] ?? true) === false, 'always_human');
        $check(($a['playbook_hold'] ?? true) === false, 'playbook_hold');
        $check(self::inRollout((int) ($in['rollout_bucket'] ?? 100), (int) ($playbook['rollout_percent'] ?? 0)), 'rollout');
        $check(($in['validator_passed'] ?? null) === true, 'validator');
        $check((int) ($a['rewrites'] ?? 1) === 0, 'rewritten');
        $check(($in['risk_flags'] ?? ['unknown']) === [], 'risk_flags');
        $check(($a['claims_grounded'] ?? false) === true, 'claims_ungrounded');
        // Action-dependent replies: auto-send only when every proposed action
        // is set to run unattended (policy `always`; computed in
        // AutoSendContext). Such a reply is then held until its actions
        // succeed (RunEffects + AutoSendGuard via ActionGate::autoSendVerdict);
        // an action that needs a human approval keeps the reply a draft.
        $check(($in['proposed_actions'] ?? ['unknown']) === [] || ($a['actions_unattended'] ?? false) === true, 'actions_pending');
        $check($language !== '' && in_array($language, $allowedLanguages, true), 'language');
        $check(in_array((string) ($a['channel'] ?? ''), (array) ($a['allowed_channels'] ?? []), true), 'channel');
        $check(! in_array(mb_strtolower((string) ($a['sentiment'] ?? 'unknown')), self::NO_AUTO_SENTIMENTS, true), 'sentiment');
        $check(($a['human_replied'] ?? true) === false, 'human_replied');
        $check(($a['customer_blocked'] ?? true) === false, 'customer_blocked');
        $check(($a['inbound_automated'] ?? true) === false, 'inbound_automated');
        $check(($a['requested_by_human'] ?? true) === false, 'requested_by_human');
        $check((int) ($a['daily_remaining'] ?? 0) > 0, 'daily_cap');
        $check((int) ($a['customer_remaining'] ?? 0) > 0, 'customer_cap');
        $check((int) ($a['consecutive_remaining'] ?? 0) > 0, 'consecutive_cap');
        $check(($a['hourly_breaker'] ?? true) === false, 'hourly_breaker');

        return $blocked;
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $ignored
     */
    public static function onlyBlockedBy(array $blockers, array $ignored): bool
    {
        return array_diff($blockers, $ignored) === [] && ! in_array('handover', $blockers, true);
    }
}
