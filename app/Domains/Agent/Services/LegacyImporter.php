<?php

namespace App\Domains\Agent\Services;

use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\AI\Models\KbDocument;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-way, idempotent import from the Commerce Assist tables into the agent
 * tables. Safe on a fresh install (old tables empty or absent) and safe to
 * re-run: nothing that already exists is overwritten, so human edits made in
 * the new screens survive. The old tables are dropped afterwards, guarded by
 * LegacyTables::unimported() (migration 2026_12_02_000000).
 *
 *   intents (config + per-workspace commerce_assist_intents) -> playbooks
 *   commerce_assist_approved_responses                        -> agent_examples (embeddings kept)
 *   commerce_assist_live_facts                                 -> agent_knowledge_items (unscoped => needs_scope)
 *   kb_documents                                               -> agent_knowledge_items (chunked)
 */
class LegacyImporter
{
    public function __construct(
        private readonly VersionService $versions,
        private readonly KbKnowledgeSync $kbSync,
    ) {}

    /** @return array<string, int> */
    public function run(?int $workspaceId = null, bool $embed = false): array
    {
        $counts = ['workspaces' => 0, 'playbooks' => 0, 'examples' => 0, 'knowledge_facts' => 0, 'needs_scope' => 0, 'kb_chunks' => 0];

        $workspaces = Workspace::query()->when($workspaceId, fn ($q) => $q->whereKey($workspaceId))->pluck('id');
        foreach ($workspaces as $id) {
            $id = (int) $id;
            $counts['workspaces']++;

            AgentSetting::forWorkspace($id);
            app(AgentBootstrapper::class)->ensureForWorkspace($id);

            $counts['playbooks'] += Playbook::where('workspace_id', $id)->count();
            $counts['examples'] += $this->importExamples($id);
            [$facts, $needsScope] = $this->importLiveFacts($id);
            $counts['knowledge_facts'] += $facts;
            $counts['needs_scope'] += $needsScope;
            $counts['kb_chunks'] += $this->importKbDocuments($id, $embed);
        }

        return $counts;
    }

    /** Seeds one playbook per intent; a workspace that already has playbooks is left alone. */
    public function importPlaybooks(int $workspaceId): int
    {
        if (Playbook::where('workspace_id', $workspaceId)->exists()) {
            return 0;
        }

        $intents = [];
        foreach ($this->defaultIntents() as $intent) {
            $intents[(string) $intent['slug']] = $intent;
        }

        // Per-workspace edits made on the old intents screen win over the defaults.
        if (Schema::hasTable('commerce_assist_intents')) {
            foreach (DB::table('commerce_assist_intents')->where('workspace_id', $workspaceId)->get() as $row) {
                $intents[(string) $row->slug] = [
                    ...($intents[(string) $row->slug] ?? []),
                    'slug' => (string) $row->slug,
                    'name' => (string) $row->name,
                    'rules' => (string) $row->rules,
                    'always_human' => (bool) $row->always_human,
                    'sort_order' => (int) $row->sort_order,
                    'data_requirements' => json_decode((string) ($row->data_requirements ?? '[]'), true) ?: [],
                ];
            }
        }

        $created = 0;
        foreach ($intents as $slug => $intent) {
            $requirements = (array) ($intent['data_requirements'] ?? []);

            $this->versions->createPlaybook(
                $workspaceId,
                $slug,
                [
                    'name' => (string) ($intent['name'] ?? $slug),
                    // "other" would match everything; leaving it off means an
                    // unknown situation hands over as no_instruction.
                    'mode' => $slug === 'other' ? Playbook::MODE_OFF : Playbook::MODE_DRAFT,
                    'rollout_percent' => 0,
                    'always_human' => (bool) ($intent['always_human'] ?? false),
                    'priority' => max(0, 200 - (int) ($intent['sort_order'] ?? 100)),
                ],
                [
                    'when_text' => StarterContent::WHEN_TEXT[$slug] ?? 'The customer\'s latest message is mainly about: '.($intent['name'] ?? $slug).'.',
                    'rules' => StarterContent::rulesFromText($slug, (string) ($intent['rules'] ?? '')),
                    'steps' => [],
                    'allowed_actions' => [],
                    'required_evidence' => in_array('photos', $requirements, true) ? ['photos'] : [],
                    'escalation' => null,
                ],
                null,
                'Imported from Commerce Assist intent '.$slug,
            );
            $created++;
        }

        return $created;
    }

    public function importExamples(int $workspaceId): int
    {
        if (! Schema::hasTable('commerce_assist_approved_responses')) {
            return 0;
        }

        $imported = 0;
        $rows = DB::table('commerce_assist_approved_responses')->where('workspace_id', $workspaceId)->orderBy('id')->get();
        foreach ($rows as $row) {
            if (AgentExample::where('legacy_approved_response_id', $row->id)->exists()) {
                continue;
            }

            AgentExample::create([
                'workspace_id' => $workspaceId,
                'conversation_id' => $row->conversation_id,
                'thread_id' => $row->thread_id,
                'legacy_approved_response_id' => $row->id,
                'customer_message' => (string) $row->customer_message,
                'reply' => (string) $row->final_reply,
                'playbook_slug' => $row->subtype ?: $row->intent,
                'sentiment' => $row->sentiment,
                'country' => $row->country,
                'tags' => json_decode((string) ($row->tags ?? '[]'), true) ?: [],
                'source' => 'legacy:'.($row->source ?? 'manual'),
                'status' => 'active',
                // pgvector text form is copied as-is: the vector is kept.
                'embedding' => $row->embedding ?? null,
                'embedding_model' => $row->embedding !== null ? 'legacy' : null,
                'created_by' => $row->created_by,
            ]);
            $imported++;
        }

        return $imported;
    }

    /** @return array{0: int, 1: int} [imported, of which needs_scope] */
    public function importLiveFacts(int $workspaceId): array
    {
        if (! Schema::hasTable('commerce_assist_live_facts')) {
            return [0, 0];
        }

        $imported = 0;
        $needsScope = 0;
        $rows = DB::table('commerce_assist_live_facts')->where('workspace_id', $workspaceId)->orderBy('id')->get();
        foreach ($rows as $row) {
            $exists = KnowledgeItem::where('workspace_id', $workspaceId)
                ->where('source_type', 'live_fact')
                ->where('source_id', $row->id)
                ->exists();
            if ($exists) {
                continue;
            }

            $scope = KnowledgeItem::normalizeScope([
                'playbooks' => json_decode((string) ($row->intent_slugs ?? '[]'), true) ?: [],
                'products' => json_decode((string) ($row->product_keywords ?? '[]'), true) ?: [],
                'countries' => [],
                'global' => false,
            ]);

            // A fact with neither intent nor keywords applied everywhere in the
            // old module. It must not silently become global: a human scopes it.
            $status = match (true) {
                $row->retired_at !== null => KnowledgeItem::STATUS_RETIRED,
                KnowledgeItem::scopeIsEmpty($scope) => KnowledgeItem::STATUS_NEEDS_SCOPE,
                default => KnowledgeItem::STATUS_ACTIVE,
            };

            KnowledgeItem::create([
                'workspace_id' => $workspaceId,
                'kind' => 'fact',
                'title' => $row->title,
                'body' => (string) $row->body,
                'scope' => $scope,
                'valid_until' => $row->expires_at,
                'status' => $status,
                'source_type' => 'live_fact',
                'source_id' => $row->id,
                'content_hash' => hash('sha256', (string) $row->title."\n".(string) $row->body),
                'created_by' => $row->created_by,
            ]);

            $imported++;
            if ($status === KnowledgeItem::STATUS_NEEDS_SCOPE) {
                $needsScope++;
            }
        }

        return [$imported, $needsScope];
    }

    public function importKbDocuments(int $workspaceId, bool $embed = false): int
    {
        $chunks = 0;
        KbDocument::query()
            ->whereHas('knowledgeBase', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('id')
            ->chunkById(100, function ($documents) use (&$chunks, $embed) {
                foreach ($documents as $document) {
                    $chunks += $this->kbSync->sync($document, $embed);
                }
            });

        return $chunks;
    }

    /** @return list<array<string, mixed>> */
    private function defaultIntents(): array
    {
        $intents = require app_path('Domains/Agent/Resources/starter_intents.php');

        return is_array($intents) ? array_values($intents) : [];
    }
}
