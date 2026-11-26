<?php

namespace App\Domains\Agent\Mining\Stages;

use App\Ai\EmbeddingService;
use App\Domains\Agent\Mining\Agents\MergeSituationsAgent;
use App\Domains\Agent\Mining\BudgetExhausted;
use App\Domains\Agent\Mining\MiningLlm;
use App\Domains\Agent\Mining\MiningPrompts;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Mining\Models\MiningSituation;
use App\Domains\Agent\Mining\PlaybookCatalogue;
use App\Domains\Agent\Pipeline\UntrustedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stage C: situation labels → 15-30 situations.
 *
 *   1. exact: a label equal to a playbook slug (dots and underscores alike,
 *      word order and plurals ignored) is that playbook;
 *   2. loose-key merge of the remaining labels ("parcel_lost" / "lost_parcels");
 *   3. semantic merge of what is left: embeddings when the workspace has them
 *      configured (cosine to playbooks, then leader clustering), otherwise
 *      one cheap history_extraction call (cached by input). If embeddings
 *      fail, the model merge runs instead; if that fails too, step 2 stands.
 *   4. situations ranked by conversations: under cluster.min_conversations
 *      = too_small, beyond cluster.max_situations = long_tail; "other",
 *      "spam" and unlabelled are never synthesised.
 *
 * The stage is one pass, recomputed from scratch if interrupted.
 */
class SituationClusterer
{
    private const NEVER_SYNTHESISE = ['other', 'spam', 'unlabelled'];

    public function __construct(
        private readonly PlaybookCatalogue $catalogue,
        private readonly MiningPrompts $prompts,
        private readonly MiningLlm $llm,
        private readonly EmbeddingService $embeddings,
    ) {}

    public function cluster(MiningRun $run): void
    {
        $catalogue = $this->catalogue->forWorkspace($run->workspace_id);
        $bySlug = [];
        $byLoose = [];
        foreach ($catalogue as $p) {
            $bySlug[PlaybookCatalogue::normaliseLabel($p['slug'])] = $p;
            $byLoose[PlaybookCatalogue::looseKey($p['slug'])] ??= $p;
        }

        // label => {count, ids, description}
        $labels = [];
        $rows = DB::table('agent_mining_conversations as mc')
            ->join('agent_mining_extractions as e', 'e.id', '=', 'mc.extraction_id')
            ->where('mc.run_id', $run->id)
            ->where('mc.status', 'done')
            ->where('e.is_support', true)
            ->orderBy('e.id')
            ->get(['e.id', 'e.situation', DB::raw("e.data->>'situation_description' as description")]);
        foreach ($rows as $row) {
            $label = PlaybookCatalogue::normaliseLabel((string) ($row->situation ?? 'unlabelled'));
            $labels[$label] ??= ['count' => 0, 'ids' => [], 'description' => ''];
            $labels[$label]['count']++;
            $labels[$label]['ids'][] = (int) $row->id;
            if ($labels[$label]['description'] === '' && filled($row->description)) {
                $labels[$label]['description'] = mb_substr((string) $row->description, 0, 240);
            }
        }

        // 1-2. exact playbook matches, then loose-key groups.
        $target = []; // label => situation key
        $new = [];    // key => {name, description}
        $loose = [];  // loose key => canonical label
        uasort($labels, fn ($a, $b) => $b['count'] <=> $a['count']);
        foreach ($labels as $label => $info) {
            $key = PlaybookCatalogue::looseKey($label);
            if (isset($bySlug[$label])) {
                $target[$label] = $bySlug[$label]['slug'];
            } elseif (isset($byLoose[$key])) {
                $target[$label] = $byLoose[$key]['slug'];
            } elseif (isset($loose[$key])) {
                $target[$label] = $loose[$key];
            } else {
                $loose[$key] = $label;
                $target[$label] = $label;
                $new[$label] = ['name' => ucfirst(str_replace(['_', '.'], ' ', $label)), 'description' => $info['description']];
            }
        }

        // 3. semantic merge of the new labels.
        $method = 'exact';
        $open = array_values(array_filter(array_keys($new), fn ($l) => ! in_array($l, self::NEVER_SYNTHESISE, true)));
        if ($open !== []) {
            $merge = null;
            if ($this->embeddings->isConfigured($run->workspace_id)) {
                try {
                    $merge = $this->embeddingMerge($run, $open, $new, $labels, $catalogue);
                    $method = 'embeddings';
                } catch (Throwable $e) {
                    Log::warning('Mining: embedding merge failed, using the model merge', ['run_id' => $run->id, 'error' => $e->getMessage()]);
                }
            }
            if ($merge === null) {
                $merge = $this->modelMerge($run, $open, $new, $labels, $catalogue);
                $method = $merge === null ? 'loose' : 'model';
            }
            if ($merge !== null) {
                [$remap, $created] = $merge;
                foreach ($target as $label => $to) {
                    if (isset($remap[$to])) {
                        $target[$label] = $remap[$to];
                    }
                }
                foreach ($remap as $from => $to) {
                    if ($from !== $to) {
                        unset($new[$from]);
                    }
                }
                foreach ($created as $key => $info) {
                    $new[$key] = $info;
                }
            }
        }

        // 4. situations.
        $situations = [];
        foreach ($labels as $label => $info) {
            $key = $target[$label];
            $situations[$key] ??= ['labels' => [], 'ids' => []];
            $situations[$key]['labels'][$label] = $info['count'];
            $situations[$key]['ids'] = [...$situations[$key]['ids'], ...$info['ids']];
        }
        uasort($situations, fn ($a, $b) => count($b['ids']) <=> count($a['ids']));

        $min = max(1, (int) config('agent_mining.cluster.min_conversations', 3));
        $max = max(1, (int) config('agent_mining.cluster.max_situations', 30));
        $playbooks = [];
        foreach ($catalogue as $p) {
            $playbooks[$p['slug']] = $p;
        }

        DB::transaction(function () use ($run, $situations, $playbooks, $new, $min, $max) {
            MiningSituation::query()->where('run_id', $run->id)->delete();
            $rank = 0;
            foreach ($situations as $key => $s) {
                $count = count($s['ids']);
                $playbook = $playbooks[$key] ?? null;
                $status = match (true) {
                    in_array($key, self::NEVER_SYNTHESISE, true) => MiningSituation::STATUS_LONG_TAIL,
                    $count < $min => MiningSituation::STATUS_TOO_SMALL,
                    ++$rank > $max => MiningSituation::STATUS_LONG_TAIL,
                    default => MiningSituation::STATUS_PENDING,
                };
                MiningSituation::query()->create([
                    'run_id' => $run->id,
                    'key' => mb_substr((string) $key, 0, 64),
                    'name' => mb_substr($playbook['name'] ?? ($new[$key]['name'] ?? ucfirst(str_replace(['_', '.'], ' ', (string) $key))), 0, 255),
                    'description' => $playbook['when_text'] ?? ($new[$key]['description'] ?? null),
                    'playbook_id' => $playbook['id'] ?? null,
                    'labels' => $s['labels'],
                    'extraction_ids' => array_values(array_unique($s['ids'])),
                    'conversation_count' => $count,
                    'status' => $status,
                ]);
            }
        });

        $run->setResult('clustering', [
            'method' => $method,
            'labels' => count($labels),
            'new_labels' => count(array_filter($labels, fn ($l) => ! isset($bySlug[$l]) && ! isset($byLoose[PlaybookCatalogue::looseKey($l)]), ARRAY_FILTER_USE_KEY)),
            'mapping' => $target,
        ]);
        $run->setCounters([
            'situations' => count($situations),
            'situations_to_synthesise' => MiningSituation::query()->where('run_id', $run->id)->where('status', MiningSituation::STATUS_PENDING)->count(),
            'conversations_clustered' => array_sum(array_map(fn ($s) => count($s['ids']), $situations)),
        ]);
    }

    /**
     * @param  list<string>  $open
     * @param  array<string, array{name: string, description: string}>  $new
     * @param  array<string, array{count: int, ids: list<int>, description: string}>  $labels
     * @param  list<array{id: int, slug: string, name: string, when_text: string}>  $catalogue
     * @return array{0: array<string, string>, 1: array<string, array{name: string, description: string}>}|null
     */
    private function modelMerge(MiningRun $run, array $open, array $new, array $labels, array $catalogue): ?array
    {
        $input = array_map(fn ($l) => ['label' => $l, 'count' => $labels[$l]['count'] ?? 0, 'description' => $new[$l]['description'] ?? ''], array_slice($open, 0, 300));
        $max = (int) config('agent_mining.cluster.max_situations', 30);
        $hash = MiningLlm::inputHash((string) config('agent_mining.merge_version', 'merge-v1'), [array_column($catalogue, 'slug'), $input, $max]);

        try {
            $result = $this->llm->call(
                $run,
                MiningRun::STAGE_CLUSTER,
                'history_extraction',
                new MergeSituationsAgent($this->prompts->mergeSystem($catalogue, $max)),
                $this->prompts->mergeUser($input, new UntrustedData),
                (int) config('agent_mining.cluster.expected_output_tokens', 4000),
                'merge',
                $hash,
            );
        } catch (BudgetExhausted $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Mining: model merge failed, keeping the label groups', ['run_id' => $run->id, 'error' => $e->getMessage()]);
            $run->setResult('clustering_error', mb_substr($e->getMessage(), 0, 500));

            return null;
        }

        $slugs = array_column($catalogue, 'slug');
        $created = [];
        foreach ((array) ($result['data']['new_situations'] ?? []) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $key = PlaybookCatalogue::normaliseLabel((string) ($s['key'] ?? ''));
            if ($key === 'unlabelled' || in_array($key, $slugs, true)) {
                continue;
            }
            $created[$key] = [
                'name' => mb_substr(trim((string) ($s['name'] ?? '')) ?: ucfirst(str_replace('_', ' ', $key)), 0, 120),
                'description' => mb_substr(trim((string) ($s['description'] ?? '')), 0, 400),
            ];
        }

        $remap = [];
        foreach ((array) ($result['data']['mappings'] ?? []) as $m) {
            if (! is_array($m)) {
                continue;
            }
            $label = PlaybookCatalogue::normaliseLabel((string) ($m['label'] ?? ''));
            $to = PlaybookCatalogue::normaliseLabel((string) ($m['target'] ?? ''));
            if (! in_array($label, $open, true)) {
                continue;
            }
            $slug = $this->slugFor($to, $catalogue);
            if ($slug !== null) {
                $remap[$label] = $slug;
            } elseif (isset($created[$to])) {
                $remap[$label] = $to;
            }
        }

        // Only keep new situations something maps to.
        $created = array_intersect_key($created, array_flip(array_values($remap)));

        return [$remap, $created];
    }

    /**
     * @param  list<string>  $open
     * @param  array<string, array{name: string, description: string}>  $new
     * @param  array<string, array{count: int, ids: list<int>, description: string}>  $labels
     * @param  list<array{id: int, slug: string, name: string, when_text: string}>  $catalogue
     * @return array{0: array<string, string>, 1: array<string, array{name: string, description: string}>}
     */
    private function embeddingMerge(MiningRun $run, array $open, array $new, array $labels, array $catalogue): array
    {
        $threshold = (float) config('agent_mining.cluster.similarity', 0.82);
        $texts = array_map(fn ($l) => str_replace('_', ' ', $l).': '.($new[$l]['description'] ?? ''), $open);
        $pbTexts = array_map(fn ($p) => str_replace(['_', '.'], ' ', $p['slug']).': '.$p['when_text'], $catalogue);

        $vectors = $this->embeddings->embed($run->workspace_id, [...$texts, ...$pbTexts]);
        $labelVecs = array_slice($vectors, 0, count($texts));
        $pbVecs = array_slice($vectors, count($texts));

        $remap = [];
        $leaders = []; // label => vector
        foreach ($open as $i => $label) {
            $best = null;
            $bestSim = $threshold;
            foreach ($pbVecs as $j => $vec) {
                $sim = self::cosine($labelVecs[$i], $vec);
                if ($sim >= $bestSim) {
                    [$best, $bestSim] = [$catalogue[$j]['slug'], $sim];
                }
            }
            if ($best !== null) {
                $remap[$label] = $best;

                continue;
            }
            foreach ($leaders as $leader => $vec) {
                if (self::cosine($labelVecs[$i], $vec) >= $threshold) {
                    $remap[$label] = $leader;

                    continue 2;
                }
            }
            $leaders[$label] = $labelVecs[$i];
        }

        return [$remap, []];
    }

    /** @param  list<array{id: int, slug: string, name: string, when_text: string}>  $catalogue */
    private function slugFor(string $label, array $catalogue): ?string
    {
        foreach ($catalogue as $p) {
            if (PlaybookCatalogue::normaliseLabel($p['slug']) === $label) {
                return $p['slug'];
            }
        }

        return null;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i] ?? 0.0;
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return $na > 0 && $nb > 0 ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
