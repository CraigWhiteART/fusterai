<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\PlaybookVersion;
use App\Domains\Agent\Qa\Models\QaReview;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class GetPlaybookTool extends ManagerTool
{
    public function name(): string
    {
        return 'get_playbook';
    }

    public function description(): string
    {
        return 'One playbook in full: its "when" text, rules (with ids for replace/remove ops), steps, required evidence, escalation, allowed actions (read-only), version history and 30-day run / QA counts. Defaults to the live version.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'playbook' => $schema->string()->description('Playbook id or slug')->required(),
            'version' => $schema->integer()->description('Version number to read instead of the live one'),
        ];
    }

    protected function label(array $arguments): string
    {
        return 'Reading playbook '.($arguments['playbook'] ?? '');
    }

    protected function run(array $arguments): string
    {
        $playbook = $this->playbook($arguments['playbook'] ?? null);
        if ($playbook === null) {
            return 'No such playbook in this workspace. Use list_playbooks.';
        }
        $version = isset($arguments['version']) && (int) $arguments['version'] > 0
            ? PlaybookVersion::where('playbook_id', $playbook->id)->where('version', (int) $arguments['version'])->first()
            : $playbook->liveVersion;
        $this->session->cite('playbook', $playbook->id, $playbook->name);

        $since = now()->subDays(30);
        $runs = AgentRun::where('workspace_id', $this->session->workspaceId)->where('playbook_id', $playbook->id)
            ->where('purpose', AgentRun::PURPOSE_LIVE)->where('created_at', '>=', $since)
            ->selectRaw("coalesce(decision, 'none') as decision, count(*) as n")->groupBy('decision')->toBase()->pluck('n', 'decision');
        $qa = QaReview::where('workspace_id', $this->session->workspaceId)->where('playbook_id', $playbook->id)
            ->where('status', QaReview::STATUS_REVIEWED)->whereNull('human_verdict')->where('created_at', '>=', $since)
            ->selectRaw('failure_class, count(*) as n')->groupBy('failure_class')->toBase()->pluck('n', 'failure_class');

        return self::json([
            'id' => $playbook->id,
            'slug' => $playbook->slug,
            'name' => $playbook->name,
            'mode' => $playbook->mode,
            'rollout_percent' => $playbook->rollout_percent,
            'always_human' => (bool) $playbook->always_human,
            'version' => $version === null ? null : [
                'id' => $version->id,
                'version' => $version->version,
                'is_live' => $version->id === $playbook->live_version_id,
                'when' => $version->when_text,
                'rules' => array_map(fn ($r) => ['id' => $r['id'], 'if' => $r['if'], 'then' => $r['then']], (array) ($version->rules ?? [])),
                'steps' => array_map(fn ($s) => ['id' => $s['id'], 'text' => $s['text']], (array) ($version->steps ?? [])),
                'required_evidence' => (array) ($version->required_evidence ?? []),
                'escalation' => $version->escalation,
                'allowed_actions_read_only' => (array) ($version->allowed_actions ?? []),
            ],
            'versions' => PlaybookVersion::where('playbook_id', $playbook->id)->orderByDesc('version')->limit(10)
                ->get(['id', 'version', 'change_note', 'created_at'])
                ->map(fn (PlaybookVersion $v) => ['id' => $v->id, 'version' => $v->version, 'change_note' => self::cut($v->change_note, 200), 'created_at' => $v->created_at?->toDateString()])->all(),
            'last_30_days' => ['live_runs_by_decision' => $runs->all(), 'qa_failure_classes' => $qa->all()],
        ]);
    }
}
