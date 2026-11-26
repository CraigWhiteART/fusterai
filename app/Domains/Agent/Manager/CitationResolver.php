<?php

namespace App\Domains\Agent\Manager;

use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Database\Eloquent\Model;

/**
 * Citation tags in the manager's answer ([run:123], [conversation:45],
 * [playbook:3], [proposal:7], [knowledge:12], [example:9], [qa:5],
 * [simulation:2]) are checked against this workspace: real ones become
 * citations the UI links; unknown ones are rewritten as plain text so the
 * UI never links something that does not exist.
 */
class CitationResolver
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = [
        'run' => AgentRun::class,
        'conversation' => Conversation::class,
        'playbook' => Playbook::class,
        'proposal' => Proposal::class,
        'knowledge' => KnowledgeItem::class,
        'example' => AgentExample::class,
        'qa' => QaReview::class,
        'simulation' => Simulation::class,
    ];

    private const PATTERN = '/\[(run|conversation|playbook|proposal|knowledge|example|qa|simulation):(\d+)\]/';

    /**
     * @return array{text: string, citations: list<array{type: string, id: int, label: string, source: string}>, unknown: list<string>}
     */
    public function resolve(int $workspaceId, string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER);
        $wanted = [];
        foreach ($matches as $m) {
            $wanted[$m[1]][] = (int) $m[2];
        }

        $found = [];
        foreach ($wanted as $type => $ids) {
            $model = self::TYPES[$type];
            foreach ($model::query()->where('workspace_id', $workspaceId)->whereIn('id', array_unique($ids))->get() as $row) {
                $found["{$type}:{$row->getKey()}"] = $this->label($type, $row);
            }
        }

        $citations = [];
        $unknown = [];
        $text = (string) preg_replace_callback(self::PATTERN, function ($m) use ($found, &$citations, &$unknown) {
            $key = "{$m[1]}:{$m[2]}";
            if (! isset($found[$key])) {
                $unknown[] = $key;

                return "{$m[1]} {$m[2]}";
            }
            $citations[$key] = ['type' => $m[1], 'id' => (int) $m[2], 'label' => $found[$key], 'source' => 'answer'];

            return $m[0];
        }, $text);

        return ['text' => $text, 'citations' => array_values($citations), 'unknown' => array_values(array_unique($unknown))];
    }

    private function label(string $type, Model $row): string
    {
        $label = match ($type) {
            'conversation' => (string) ($row->getAttribute('subject') ?? ''),
            'playbook' => (string) $row->getAttribute('name'),
            'proposal' => (string) $row->getAttribute('title'),
            'knowledge' => (string) ($row->getAttribute('title') ?? mb_substr((string) $row->getAttribute('body'), 0, 60)),
            default => '',
        };

        return mb_substr($label !== '' ? $label : ucfirst($type).' #'.$row->getKey(), 0, 160);
    }
}
