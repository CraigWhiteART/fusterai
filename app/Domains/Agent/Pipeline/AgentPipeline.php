<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Agent\Agents\ComposeReplyAgent;
use App\Domains\Agent\Agents\TriageAgent;
use App\Domains\Agent\Agents\ValidatorAgent;
use App\Domains\Agent\Agents\WriterAgent;
use App\Domains\Agent\AutoSend\AutoSendContext;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Domains\Agent\Services\ExampleSearch;
use App\Domains\Agent\Services\KnowledgeSearch;
use App\Domains\Agent\Tools\HandoverTool;
use App\Domains\Agent\Tools\LookupOrderTool;
use App\Domains\Agent\Tools\LookupTrackingTool;
use App\Domains\Agent\Tools\ProposeActionTool;
use App\Domains\Agent\Tools\SearchKnowledgeTool;
use App\Domains\Agent\Tools\SubmitReplyTool;
use App\Domains\Commerce\Models\CommerceSetting;
use App\Enums\ConversationStatus;
use App\Services\AiSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * The agent's drafting pipeline:
 *
 *   1. preflight      kill switch, mode, auto-reply guard, idempotency key
 *   2. transcript     full history, chronological, within a token budget
 *   3. triage         `triage` role, structured: language, sentiment, risk, playbook
 *   4. evidence       Shopify order + carrier tracking into an id'd ledger
 *   5. writer         `writer` role, bounded tool loop ending in submit_reply
 *   6. validation     deterministic checks, then `validator` role; fail closed; one rewrite
 *   7. policy gate    pure code: draft | auto_send | handover (PolicyGate)
 *   8. persist        agent_runs (+ conversation effects for live runs only)
 *
 * Seams: PipelineInput (live conversation or frozen transcript + evidence +
 * now) and InstructionSet (live versions, or live + in-memory overlay).
 * Non-live purposes never write AiSuggestion rows, broadcast, change the
 * conversation, or call Shopify / the carrier.
 */
class AgentPipeline
{
    private const STALE_RUNNING_SECONDS = 360;

    /** Stop before the job's 300s timeout; skip the rewrite past this. */
    private const REWRITE_DEADLINE_SECONDS = 200;

    public function __construct(
        private readonly AiSettingsService $ai,
        private readonly EvidenceCollector $evidence,
        private readonly PromptBuilder $prompts,
        private readonly KnowledgeSearch $knowledge,
        private readonly ExampleSearch $examples,
        private readonly DeterministicValidator $deterministic,
        private readonly RunEffects $effects,
        private readonly AgentBootstrapper $bootstrapper,
        private readonly AutoSendContext $autoSendContext,
    ) {}

    public function run(PipelineInput $input, ?InstructionSet $instructions = null, ?string $idempotencyKey = null): PipelineResult
    {
        $this->bootstrapper->ensureForWorkspace($input->workspaceId);
        $settings = AgentSetting::forWorkspace($input->workspaceId);
        $instructions ??= InstructionSet::live($input->workspaceId);

        if (($skip = $this->preflight($input, $settings)) !== null) {
            return PipelineResult::skipped($skip);
        }

        $config = $this->config($input, $instructions);
        $key = $idempotencyKey ?? $this->idempotencyKey($input, $config['config_hash']);

        $claimed = $this->claim($input, $config, $key);
        if ($claimed instanceof PipelineResult) {
            return $claimed;
        }

        try {
            $this->execute($claimed, $input, $instructions, $settings);
        } catch (Throwable $e) {
            $claimed->forceFill([
                'status' => AgentRun::STATUS_FAILED,
                'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 2000),
                'completed_at' => now(),
            ])->save();

            throw $e;
        }

        if ($input->isLive() && $input->conversation !== null) {
            $this->effects->apply($claimed, $input->conversation);
        }

        return PipelineResult::ran($claimed->fresh() ?? $claimed);
    }

    // ── 1. Preflight ─────────────────────────────────────────────────────────

    private function preflight(PipelineInput $input, AgentSetting $settings): ?string
    {
        $acting = in_array($input->purpose, [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW], true);

        if ($acting && $settings->kill_switch) {
            return 'kill_switch';
        }
        if ($input->purpose === AgentRun::PURPOSE_LIVE && ! $settings->pipelineOwnsDrafts() && ! $input->manual) {
            return 'mode_'.$settings->mode;
        }
        if ($input->purpose === AgentRun::PURPOSE_SHADOW && $settings->mode !== AgentSetting::MODE_SHADOW) {
            return 'mode_'.$settings->mode;
        }
        if ($input->conversation !== null && $input->conversation->status === ConversationStatus::Spam) {
            return 'spam';
        }

        $latest = $input->transcript->latestCustomerTurn();
        if ($latest === null) {
            return 'no_customer_message';
        }
        if ($acting && ! $input->force && ! ($input->transcript->lastTurn()?->isCustomer() ?? false)) {
            return 'already_answered';
        }
        if ($acting && ($latest->autoSubmitted || $this->looksAutomatic($input->subject, $latest->text))) {
            return 'auto_reply';
        }

        return null;
    }

    private function looksAutomatic(string $subject, string $text): bool
    {
        $pattern = '/^\s*(?:re:\s*)?(?:auto(?:matic)?[\s-]?reply|autoreply|out of (?:the )?office|delivery status notification|undeliver(?:able|ed)|mail delivery failed)\b/i';

        return preg_match($pattern, $subject) === 1 || preg_match('/^\s*(?:this is an automatic reply|i am (?:currently )?out of (?:the )?office)/i', $text) === 1;
    }

    // ── Config + idempotency ─────────────────────────────────────────────────

    /**
     * The config hash a run of these instructions would record (models,
     * versions, overlay hash, template), without running anything. Used by
     * simulations to look up cached results before spending tokens.
     */
    public function configHash(int $workspaceId, InstructionSet $instructions): string
    {
        return $this->configFor($workspaceId, $instructions, CarbonImmutable::now())['config_hash'];
    }

    /** @return array<string, mixed> */
    private function config(PipelineInput $input, InstructionSet $instructions): array
    {
        return $this->configFor($input->workspaceId, $instructions, $input->now);
    }

    /** @return array<string, mixed> */
    private function configFor(int $workspaceId, InstructionSet $instructions, CarbonImmutable $now): array
    {
        $models = [];
        foreach (['triage', 'writer', 'validator'] as $role) {
            $creds = $this->ai->resolveCredentials($workspaceId, $role);
            $models[$role] = $creds['model'] ?? $creds['lab']->value;
        }

        $config = [
            ...$instructions->versionConfig(),
            'models' => $models,
            'provider' => $this->ai->resolveCredentials($workspaceId)['provider'],
            'prompt_template_version' => (string) config('agent.prompt_template_version', 'agent-v1'),
            'writer_mode' => $this->writerMode(),
            'transcript' => config('agent.transcript'),
            'now' => $now->toIso8601String(),
        ];

        $hashed = $config;
        unset($hashed['overlay'], $hashed['now']);
        $config['config_hash'] = hash('sha256', (string) json_encode($hashed));

        return $config;
    }

    private function idempotencyKey(PipelineInput $input, string $configHash): string
    {
        if ($input->force || ! in_array($input->purpose, [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW], true) || $input->conversation === null) {
            return $input->purpose.':'.Str::uuid7()->toString();
        }

        $thread = $input->transcript->latestCustomerTurn()->threadId ?? 0;

        return $input->purpose.':'.hash('sha256', $input->conversation->id.'|'.$thread.'|'.$configHash);
    }

    /** @param  array<string, mixed>  $config */
    private function claim(PipelineInput $input, array $config, string $key): AgentRun|PipelineResult
    {
        $attributes = [
            'workspace_id' => $input->workspaceId,
            'purpose' => $input->purpose,
            'conversation_id' => $input->conversation?->id,
            'frozen_case_id' => $input->frozenCaseId,
            'customer_thread_id' => $input->transcript->latestCustomerTurn()?->threadId,
            'status' => AgentRun::STATUS_RUNNING,
            'config' => $config,
            'config_hash' => $config['config_hash'],
            'started_at' => now(),
        ];

        $existing = AgentRun::where('idempotency_key', $key)->first();
        if ($existing === null) {
            try {
                return AgentRun::create([...$attributes, 'idempotency_key' => $key]);
            } catch (UniqueConstraintViolationException) {
                $existing = AgentRun::where('idempotency_key', $key)->firstOrFail();
            }
        }

        if ($existing->status === AgentRun::STATUS_COMPLETED) {
            return PipelineResult::reused($existing);
        }
        if ($existing->status === AgentRun::STATUS_RUNNING && $existing->started_at?->gt(now()->subSeconds(self::STALE_RUNNING_SECONDS))) {
            return PipelineResult::skipped('in_progress', $existing);
        }

        // A failed or abandoned attempt (job retry): take the row over and start again.
        $existing->forceFill([...$attributes, 'error' => null, 'completed_at' => null])->save();

        return $existing;
    }

    private function writerMode(): string
    {
        return config('agent.writer.mode') === 'structured' ? 'structured' : 'tools';
    }

    // ── 2-8 ──────────────────────────────────────────────────────────────────

    private function execute(AgentRun $run, PipelineInput $input, InstructionSet $instructions, AgentSetting $settings): void
    {
        $started = microtime(true);
        $meter = new UsageMeter;
        $untrusted = new UntrustedData;
        $trace = ['nonce' => $untrusted->nonce];

        // 2. Transcript
        $tcfg = (array) config('agent.transcript', []);
        $rendered = $input->transcript->render((int) ($tcfg['max_tokens'] ?? 6000), (int) ($tcfg['verbatim_turns'] ?? 6), (int) ($tcfg['turn_max_chars'] ?? 4000));
        $trace['transcript'] = ['included' => $rendered['included'], 'summarised' => $rendered['summarised'], 'dropped' => $rendered['dropped']];

        // 3. Triage
        $triage = $this->triage($input, $instructions, $untrusted, $rendered['text'], $meter, $trace);
        $playbook = $instructions->playbook($triage['playbook']);

        // 4. Evidence
        $ledger = $this->evidence->collect($input);

        $productHaystack = mb_strtolower(implode(' ', array_map(fn ($f) => $f['value'], array_filter(
            $ledger->facts('shopify'),
            fn ($f) => str_starts_with($f['key'], 'shopify.product.'),
        ))).' '.$input->subject.' '.$input->transcript->customerText());
        $country = $ledger->valueOf('shopify.shipping_country_code');

        $missing = $playbook !== null ? $this->missingEvidence($playbook, $ledger, $input) : [];

        $state = [
            'draft' => null,
            'claims' => [],
            'rules_used' => [],
            'writer_handover' => null,
            'writer_note' => null,
            'knowledge' => [],
            'proposed_actions' => [],
            'validator' => ['deterministic' => [], 'llm' => null, 'rewrites' => 0],
            'validator_passed' => null,
            'validator_error' => false,
        ];

        $preGate = PolicyGate::decide($this->gateInput($settings, $playbook, $input, $triage, $ledger, $missing, [
            ...$state, 'validator_passed' => true, 'draft' => 'pending',
        ]));
        $writerRuns = $preGate['decision'] !== PolicyGate::HANDOVER;

        if ($writerRuns && $playbook !== null) {
            $state = $this->writeAndValidate($input, $instructions, $playbook, $ledger, $untrusted, $rendered['text'], $productHaystack, $country, $meter, $trace, $started, $state);
        }

        // 7. Policy gate (with the auto-send context for live / shadow runs)
        $gateInput = $this->gateInput($settings, $playbook, $input, $triage, $ledger, $missing, $state, withAutoSend: true);
        $gate = PolicyGate::decide($gateInput);
        $trace['gate'] = $gate;
        $trace['auto_send_context'] = $gateInput['auto_send'];
        $handover = $gate['decision'] === PolicyGate::HANDOVER;

        // 8. Persist
        $run->forceFill([
            'status' => AgentRun::STATUS_COMPLETED,
            'triage' => $triage,
            'playbook_id' => $playbook?->id,
            'playbook_version_id' => $playbook?->versionId,
            'evidence' => [...$ledger->toArray(), 'knowledge' => array_values($state['knowledge'])],
            'draft' => $handover ? null : $state['draft'],
            'internal_note' => $handover
                ? $this->internalNote((string) $gate['reason'], $triage, $playbook, $ledger, $state)
                : $state['writer_note'],
            'claims' => $state['claims'],
            'rules_used' => $state['rules_used'],
            'proposed_actions' => $state['proposed_actions'],
            'validator' => [
                ...$state['validator'],
                'passed' => $state['validator_passed'],
                'error' => $state['validator_error'],
                'rejected_draft' => $handover ? $state['draft'] : null,
            ],
            'decision' => $gate['decision'],
            'handover_reason' => $gate['reason'],
            'auto_send' => [
                'blockers' => $gate['auto_send_blockers'],
                'would_auto_send' => AutoSendContext::wouldAutoSend($gate),
                'eligible_if_auto' => AutoSendContext::eligibleIfAuto($gate),
                'status' => $gate['decision'] === PolicyGate::AUTO_SEND ? 'decided' : null,
            ],
            'tokens_in' => $meter->in,
            'tokens_out' => $meter->out,
            'tokens_cache_read' => $meter->cacheRead,
            'tokens_cache_write' => $meter->cacheWrite,
            'tokens_reasoning' => $meter->reasoning,
            'cost_micro_usd' => $meter->microUsd,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'trace' => [...$trace, 'usage' => $meter->calls],
            'completed_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array{language: string, sentiment: string, risk_flags: list<string>, playbook: string|null, confidence: float|null, reason: string, error: string|null, heuristics: list<string>}
     */
    private function triage(PipelineInput $input, InstructionSet $instructions, UntrustedData $untrusted, string $transcript, UsageMeter $meter, array &$trace): array
    {
        $catalogue = $instructions->catalogue();
        $system = $this->prompts->triageSystem($instructions);
        $user = $this->prompts->triageUser($input, $untrusted, $transcript);
        $heuristics = $this->injectionHeuristics($input);

        $result = [
            'language' => 'unknown',
            'sentiment' => 'unknown',
            'risk_flags' => $heuristics !== [] ? ['prompt_injection'] : [],
            'playbook' => null,
            'confidence' => null,
            'reason' => '',
            'error' => null,
            'heuristics' => $heuristics,
        ];
        $trace['triage'] = ['system' => $system, 'user' => $user];

        if ($catalogue === []) {
            $result['reason'] = 'No active playbooks.';

            return $result;
        }

        try {
            $response = $this->ai->withWorkspaceCredentials(
                $input->workspaceId,
                fn ($lab, $model, $options = []) => (new TriageAgent($system, array_keys($catalogue)))
                    ->withProviderOptions($options)
                    ->prompt($user, provider: $lab, model: $model),
                task: 'triage',
            );
            $meter->record('triage', $response->meta->model ?? null, $response->usage);
            $data = method_exists($response, 'toArray') ? $response->toArray() : [];
            $trace['triage']['output'] = $data;
        } catch (Throwable $e) {
            Log::warning('Agent triage failed', ['workspace_id' => $input->workspaceId, 'error' => $e->getMessage()]);
            $result['error'] = class_basename($e).': '.mb_substr($e->getMessage(), 0, 500);
            $result['risk_flags'][] = 'triage_error';

            return $result;
        }

        $slug = (string) ($data['playbook'] ?? 'none');
        $flags = array_values(array_intersect((array) ($data['risk_flags'] ?? []), TriageAgent::RISK_FLAGS));

        return [
            ...$result,
            'language' => (string) ($data['language'] ?? 'unknown'),
            'sentiment' => (string) ($data['sentiment'] ?? 'unknown'),
            'risk_flags' => array_values(array_unique([...$result['risk_flags'], ...$flags])),
            'playbook' => isset($catalogue[$slug]) ? $slug : null,
            'confidence' => isset($data['confidence']) ? (float) $data['confidence'] : null,
            'reason' => mb_substr((string) ($data['reason'] ?? ''), 0, 500),
        ];
    }

    /**
     * Defence in depth: obvious injection phrasing raises the flag even if
     * the triage model misses it.
     *
     * @return list<string>
     */
    private function injectionHeuristics(PipelineInput $input): array
    {
        $text = mb_strtolower($input->subject."\n".($input->transcript->latestCustomerTurn()->text ?? ''));
        $patterns = [
            'ignore_instructions' => '/\b(?:ignore|disregard|forget|override)\b[^.\n]{0,40}\b(?:instructions?|rules|prompt|guidelines|polic(?:y|ies))\b/u',
            'role_override' => '/\b(?:you are now|act as|pretend to be|new instructions?:|system prompt|developer mode|jailbreak)\b/u',
            'fake_system' => '/(?:^|\n)\s*(?:system|assistant)\s*:/u',
        ];

        $hits = [];
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $hits[] = $name;
            }
        }

        return $hits;
    }

    /** @return list<string> */
    private function missingEvidence(PlaybookSpec $playbook, EvidenceLedger $ledger, PipelineInput $input): array
    {
        $missing = [];
        foreach ($playbook->requiredEvidence as $required) {
            $ok = match ($required) {
                'order' => (bool) $ledger->meta('order_found') && ! $ledger->meta('order_withheld'),
                'tracking' => (bool) $ledger->meta('tracking_found'),
                'photos' => $input->transcript->hasCustomerImages(),
                default => true,
            };
            if (! $ok) {
                $missing[] = (string) $required;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    private function writeAndValidate(
        PipelineInput $input,
        InstructionSet $instructions,
        PlaybookSpec $playbook,
        EvidenceLedger $ledger,
        UntrustedData $untrusted,
        string $transcript,
        string $productHaystack,
        ?string $country,
        UsageMeter $meter,
        array &$trace,
        float $started,
        array $state,
    ): array {
        $query = trim($input->subject.' '.mb_substr($input->transcript->latestCustomerTurn()->text ?? '', 0, 1500));
        $prefetched = $this->knowledge->search($instructions, $query, $playbook->slug, $productHaystack, $country, $input->now, (int) config('agent.knowledge.prefetch_limit', 5));
        $examples = $this->examples->search($input->workspaceId, $query, $playbook->slug, (int) config('agent.examples.limit', 3), $instructions->examplesRemove);
        $trace['examples'] = array_map(fn ($e) => $e['id'], $examples);

        $session = new WriterSession($input, $instructions, $playbook, $ledger, $untrusted, $productHaystack, $country);
        $session->rememberKnowledge($prefetched);

        $toolMode = $this->writerMode() === 'tools';
        $system = $this->prompts->writerSystem($instructions, $playbook, $prefetched, $toolMode);
        $user = $this->prompts->writerUser($input, $untrusted, $transcript, $ledger, $examples);

        try {
            $out = $toolMode
                ? $this->writeWithTools($session, $system, $user, $meter, $trace)
                : $this->compose($input, $system, $user, $meter, $trace, 'writer');
        } catch (Throwable $e) {
            Log::warning('Agent writer failed', ['workspace_id' => $input->workspaceId, 'error' => $e->getMessage()]);
            $trace['writer_error'] = class_basename($e).': '.mb_substr($e->getMessage(), 0, 500);

            return [...$state, 'writer_handover' => 'writer_error', 'knowledge' => $session->knowledge, 'proposed_actions' => $session->proposedActions];
        }

        $state = $this->absorb($state, $out, $session, $playbook);
        if ($state['writer_handover'] !== null || trim((string) $state['draft']) === '') {
            return $state;
        }

        // 6. Validation, fail closed, at most one rewrite.
        $allowed = $this->allowedDomains($input->workspaceId);
        for ($attempt = 0; ; $attempt++) {
            $issues = $this->deterministic->check((string) $state['draft'], $state['claims'], $ledger, $session->knowledge, $instructions->voice, $allowed);
            $state['validator']['deterministic'][] = $issues;
            $llm = null;

            if ($issues === []) {
                try {
                    $llm = $this->llmValidate($input, $instructions, $playbook, $untrusted, $ledger, $session, $state, $meter, $trace);
                    $state['validator']['llm'] = $llm;
                } catch (Throwable $e) {
                    Log::warning('Agent validator failed', ['workspace_id' => $input->workspaceId, 'error' => $e->getMessage()]);
                    $state['validator']['llm'] = ['error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 500)];
                    $state['validator_error'] = true;
                    $state['validator_passed'] = false;

                    return $state;
                }

                if ($llm['verdict'] === 'pass') {
                    $state['validator_passed'] = true;

                    return $state;
                }
            }

            $state['validator_passed'] = false;
            $problems = $issues !== []
                ? array_map(fn ($i) => $i['problem'], $issues)
                : array_map(fn ($i) => '"'.$i['quote'].'": '.$i['problem'], $llm['issues'] ?? []);

            if ($attempt === 1 || (microtime(true) - $started) > self::REWRITE_DEADLINE_SECONDS) {
                return $state;
            }

            // One rewrite: structured, no tools, with the problems listed as data.
            $state['validator']['rewrites'] = 1;
            $rewriteUser = $this->prompts->writerUser($input, $untrusted, $transcript, $ledger, $examples, $problems);
            $rewriteSystem = $this->prompts->writerSystem($instructions, $playbook, array_values($session->knowledge), false);

            try {
                $out = $this->compose($input, $rewriteSystem, $rewriteUser, $meter, $trace, 'rewrite');
            } catch (Throwable $e) {
                $trace['rewrite_error'] = class_basename($e).': '.mb_substr($e->getMessage(), 0, 500);

                return $state;
            }

            $state = $this->absorb($state, $out, $session, $playbook);
            if ($state['writer_handover'] !== null || trim((string) $state['draft']) === '') {
                return $state;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    private function writeWithTools(WriterSession $session, string $system, string $user, UsageMeter $meter, array &$trace): array
    {
        $collector = $this->evidence;
        $tools = [
            new SearchKnowledgeTool($session, $this->knowledge),
            new LookupOrderTool($session, $collector),
            new LookupTrackingTool($session),
            new ProposeActionTool($session),
            new HandoverTool($session),
            new SubmitReplyTool($session),
        ];

        /** @var AgentResponse $response */
        $response = $this->ai->withWorkspaceCredentials(
            $session->input->workspaceId,
            fn ($lab, $model, $options = []) => (new WriterAgent($system, $tools))
                ->withProviderOptions($options)
                ->prompt($user, provider: $lab, model: $model),
            task: 'writer',
        );
        $meter->record('writer', $response->meta->model ?? null, $response->usage);

        $trace['writer'] = [
            'mode' => 'tools',
            'system' => $system,
            'user' => $user,
            'text' => mb_substr((string) $response->text, 0, 4000),
            'steps' => $response->steps->map(fn ($step) => [
                'text' => mb_substr((string) $step->text, 0, 2000),
                'finish_reason' => $step->finishReason->value,
                'tool_calls' => array_map(fn ($c) => ['name' => $c->name, 'arguments' => $c->arguments], $step->toolCalls),
            ])->values()->all(),
            'tool_log' => $session->toolLog,
        ];

        if ($session->handover !== null) {
            return ['handover' => $session->handover];
        }
        if ($session->submission !== null) {
            return ['submission' => $session->submission];
        }

        // The loop ended without submit_reply (step limit, or the model answered
        // in prose): one structured call over the same evidence finishes it.
        $trace['writer']['fallback'] = 'compose';
        $composeSystem = $this->prompts->writerSystem($session->instructions, $session->playbook, array_values($session->knowledge), false);

        return $this->compose($session->input, $composeSystem, $user, $meter, $trace, 'writer_fallback');
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    private function compose(PipelineInput $input, string $system, string $user, UsageMeter $meter, array &$trace, string $step): array
    {
        $response = $this->ai->withWorkspaceCredentials(
            $input->workspaceId,
            fn ($lab, $model, $options = []) => (new ComposeReplyAgent($system))
                ->withProviderOptions($options)
                ->prompt($user, provider: $lab, model: $model),
            task: 'writer',
        );
        $meter->record($step, $response->meta->model ?? null, $response->usage);
        $data = method_exists($response, 'toArray') ? $response->toArray() : [];
        $trace[$step] = ['mode' => 'structured', 'system' => $system, 'user' => $user, 'output' => $data];

        return ['submission' => $data];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $out
     * @return array<string, mixed>
     */
    private function absorb(array $state, array $out, WriterSession $session, PlaybookSpec $playbook): array
    {
        $state['knowledge'] = $session->knowledge;
        $state['proposed_actions'] = $session->proposedActions;

        if (isset($out['handover'])) {
            $state['writer_handover'] = (string) $out['handover']['reason'];
            $state['writer_note'] = (string) $out['handover']['detail'];
            $state['draft'] = null;

            return $state;
        }

        $sub = (array) ($out['submission'] ?? []);
        $reason = $sub['handover_reason'] ?? null;
        $state['writer_note'] = isset($sub['internal_note']) ? mb_substr((string) $sub['internal_note'], 0, 2000) : null;

        if (is_string($reason) && trim($reason) !== '') {
            $state['writer_handover'] = mb_substr(Str::snake(trim($reason)), 0, 60);
            $state['draft'] = null;

            return $state;
        }

        $state['draft'] = trim((string) ($sub['reply'] ?? ''));
        $state['claims'] = array_values(array_map(fn ($claim) => [
            'text' => (string) ($claim['text'] ?? ''),
            'sources' => array_values(array_map('strval', (array) ($claim['sources'] ?? []))),
        ], array_filter((array) ($sub['claims'] ?? []), 'is_array')));

        $known = $playbook->ruleIds();
        $state['rules_used'] = array_values(array_intersect(array_map('strval', (array) ($sub['rules_used'] ?? [])), $known));

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $trace
     * @return array{verdict: string, issues: list<array{quote: string, problem: string}>}
     */
    private function llmValidate(PipelineInput $input, InstructionSet $instructions, PlaybookSpec $playbook, UntrustedData $untrusted, EvidenceLedger $ledger, WriterSession $session, array $state, UsageMeter $meter, array &$trace): array
    {
        $system = $this->prompts->validatorSystem($instructions, $playbook);
        $user = $this->prompts->validatorUser($input, $untrusted, (string) $state['draft'], $state['claims'], $ledger, array_values($session->knowledge));

        $response = $this->ai->withWorkspaceCredentials(
            $input->workspaceId,
            fn ($lab, $model, $options = []) => (new ValidatorAgent($system))
                ->withProviderOptions($options)
                ->prompt($user, provider: $lab, model: $model),
            task: 'validator',
        );
        $meter->record('validator', $response->meta->model ?? null, $response->usage);

        $data = method_exists($response, 'toArray') ? $response->toArray() : [];
        $trace['validator'][] = ['system' => $system, 'user' => $user, 'output' => $data];

        $verdict = ($data['verdict'] ?? null) === 'pass' ? 'pass' : 'fail';
        $issues = array_values(array_map(fn ($i) => [
            'quote' => mb_substr((string) ($i['quote'] ?? ''), 0, 500),
            'problem' => mb_substr((string) ($i['problem'] ?? ''), 0, 500),
        ], array_filter((array) ($data['issues'] ?? []), 'is_array')));

        // A "pass" that still lists issues is not a pass.
        if ($verdict === 'pass' && $issues !== []) {
            $verdict = 'fail';
        }

        return ['verdict' => $verdict, 'issues' => $issues];
    }

    /** @return list<string> */
    private function allowedDomains(int $workspaceId): array
    {
        $domains = (array) config('agent.carrier_domains', []);
        $shop = CommerceSetting::forWorkspace($workspaceId)->shopDomain();
        if ($shop !== null) {
            $domains[] = $shop;
        }

        return array_values(array_unique(array_filter(array_map('strval', [
            ...$domains,
            ...(AgentSetting::forWorkspace($workspaceId)->link_allowlist ?? []),
        ]))));
    }

    /**
     * @param  array<string, mixed>  $triage
     * @param  list<string>  $missing
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function gateInput(AgentSetting $settings, ?PlaybookSpec $playbook, PipelineInput $input, array $triage, EvidenceLedger $ledger, array $missing, array $state, bool $withAutoSend = false): array
    {
        $flags = array_values(array_filter((array) $triage['risk_flags'], fn ($f) => $f !== 'triage_error'));
        $triageFailed = in_array('triage_error', (array) $triage['risk_flags'], true);

        return [
            'agent_mode' => $settings->mode,
            'kill_switch' => in_array($input->purpose, [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW], true) && $settings->kill_switch,
            'triage_failed' => $triageFailed,
            'playbook' => $playbook === null ? null : [
                'mode' => $playbook->mode,
                'rollout_percent' => $playbook->rolloutPercent,
                'always_human' => $playbook->alwaysHuman,
            ],
            'rollout_bucket' => PolicyGate::rolloutBucket($input->conversation->id ?? $input->frozenCaseId),
            'risk_flags' => $flags,
            'writer_handover' => $state['writer_handover'],
            'order_withheld' => (bool) $ledger->meta('order_withheld'),
            'missing_evidence' => $missing,
            'validator_passed' => $state['validator_passed'],
            'validator_error' => (bool) $state['validator_error'],
            'has_draft' => trim((string) $state['draft']) !== '',
            'proposed_actions' => array_values(array_filter($state['proposed_actions'], fn ($a) => ($a['status'] ?? '') === 'proposed')),
            // Phase 8: null (= never auto-send) for preview / simulation / test runs.
            'auto_send' => $withAutoSend ? $this->autoSendContext->build($settings, $playbook, $input, $triage, $state, $ledger) : null,
        ];
    }

    /**
     * What the human needs when the agent stops: why, what it found, and what
     * it would have needed. Never a customer-facing answer.
     *
     * @param  array<string, mixed>  $triage
     * @param  array<string, mixed>  $state
     */
    private function internalNote(string $reason, array $triage, ?PlaybookSpec $playbook, EvidenceLedger $ledger, array $state): string
    {
        $lines = ['Why: '.HandoverReasons::label($reason).'.'];
        $lines[] = 'Playbook: '.($playbook !== null ? "{$playbook->name} ({$playbook->slug})" : 'none matched').
            (filled($triage['reason'] ?? null) ? ' - '.$triage['reason'] : '');

        if (($triage['risk_flags'] ?? []) !== []) {
            $lines[] = 'Risk flags: '.implode(', ', (array) $triage['risk_flags']).'.';
        }
        $lines[] = 'Sentiment: '.($triage['sentiment'] ?? 'unknown').'; language: '.($triage['language'] ?? 'unknown').'.';

        $order = $ledger->valueOf('shopify.order_number');
        if ($order !== null) {
            $parts = array_filter([
                'order '.$order,
                $ledger->valueOf('shopify.fulfilment_status'),
                $ledger->valueOf('shopify.payment_status'),
                $ledger->valueOf('tracking.status_label') ? 'carrier: '.$ledger->valueOf('tracking.status_label') : null,
                $ledger->valueOf('tracking.days_since_last_scan') !== null ? 'last scan '.$ledger->valueOf('tracking.days_since_last_scan').'d ago' : null,
            ]);
            $lines[] = 'Found: '.implode(', ', $parts).'.';
        }
        foreach ($ledger->notes() as $note) {
            $lines[] = 'Note: '.$note;
        }
        if (filled($state['writer_note'] ?? null)) {
            $lines[] = 'Writer: '.$state['writer_note'];
        }

        $issues = [];
        foreach ((array) ($state['validator']['deterministic'] ?? []) as $round) {
            foreach ((array) $round as $issue) {
                $issues[] = $issue['problem'];
            }
        }
        foreach ((array) ($state['validator']['llm']['issues'] ?? []) as $issue) {
            $issues[] = $issue['problem'];
        }
        if ($issues !== []) {
            $lines[] = 'Validator: '.implode(' ', array_slice(array_unique($issues), 0, 5));
        }
        foreach ($state['proposed_actions'] ?? [] as $action) {
            if (($action['status'] ?? '') === 'proposed') {
                $lines[] = 'Proposed action (not executed): '.$action['action'].' - '.$action['reason'];
            }
        }

        return implode("\n", $lines);
    }
}
