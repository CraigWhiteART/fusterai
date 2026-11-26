<?php

require_once __DIR__.'/AgentTestHelpers.php';

use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\PlaybookVersion;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Domains\Agent\Services\KbKnowledgeSync;
use App\Domains\Agent\Services\KnowledgeChunker;
use App\Domains\Agent\Services\LegacyImporter;
use App\Domains\Agent\Services\StarterContent;
use App\Domains\Agent\Services\VersionService;
use App\Domains\AI\Models\KbDocument;
use App\Domains\AI\Models\KnowledgeBase;
use App\Domains\AI\Models\Module;
use App\Models\Workspace;
use App\Services\KnowledgeBaseService;
use Illuminate\Support\Facades\DB;

test('a new workspace is seeded with a starter voice and one playbook per intent', function () {
    $workspace = Workspace::factory()->create();
    app(AgentBootstrapper::class)->ensureForWorkspace($workspace->id);

    $settings = AgentSetting::forWorkspace($workspace->id);
    $voice = VoiceVersion::findOrFail($settings->live_voice_version_id);
    $playbooks = Playbook::with('liveVersion')->where('workspace_id', $workspace->id)->get()->keyBy('slug');

    expect($settings->mode)->toBe('off')
        ->and($voice->is_starter)->toBeTrue()
        ->and($voice->personality)->toContain('STARTING POINT')
        ->and($voice->dontPhraseTexts())->toContain('I completely understand your frustration')
        ->and($playbooks)->toHaveCount(count(require app_path('Domains/Agent/Resources/starter_intents.php')))
        ->and($playbooks['other']->mode)->toBe('off')
        ->and($playbooks['refund_request']->always_human)->toBeTrue()
        ->and($playbooks['order_status']->liveVersion->rules)->not->toBeEmpty();

    // Rule ids are stable: converting again gives the same ids.
    $rules = $playbooks['order_status']->liveVersion->rules;
    expect(StarterContent::rulesFromText('order_status', "- If no matching order is found, say so.\n- Do not invent."))
        ->toBe(StarterContent::rulesFromText('order_status', "- If no matching order is found, say so.\n- Do not invent."))
        ->and(collect($rules)->firstWhere('if', 'no matching order is found'))->not->toBeNull();
});

test('agent mode defaults to draft when the legacy module is active', function () {
    Module::create(['alias' => 'CommerceAssist', 'name' => 'Commerce Assist', 'active' => true, 'version' => '1', 'config' => []]);
    $workspace = Workspace::factory()->create();

    expect(AgentSetting::forWorkspace($workspace->id)->mode)->toBe('draft');
});

test('editing creates an immutable new version and restore copies an old one', function () {
    $workspace = Workspace::factory()->create();
    $versions = app(VersionService::class);

    $playbook = $versions->createPlaybook($workspace->id, 'warranty', ['name' => 'Warranty'], [
        'when_text' => 'Warranty questions',
        'rules' => [['if' => 'always', 'then' => 'Warranty is 12 months.']],
    ]);
    $v1 = $playbook->liveVersion;
    $ruleId = $v1->rules[0]['id'];

    $v2 = $versions->createPlaybookVersion($playbook, [
        'when_text' => 'Warranty questions',
        'rules' => [['id' => $ruleId, 'if' => 'always', 'then' => 'Warranty is 24 months.'], ['then' => 'Ask for proof of purchase.']],
    ]);

    expect($v2->version)->toBe(2)
        ->and($v2->rules[0]['id'])->toBe($ruleId)
        ->and($v2->rules[1]['id'])->toStartWith('r-')
        ->and($playbook->fresh()->live_version_id)->toBe($v2->id)
        ->and(fn () => $v1->fresh()->update(['when_text' => 'tampered']))->toThrow(LogicException::class);

    $v3 = $versions->restorePlaybook($playbook->fresh(), $v1);
    expect($v3->version)->toBe(3)
        ->and($v3->restored_from_id)->toBe($v1->id)
        ->and($v3->rules)->toBe($v1->rules)
        ->and($playbook->fresh()->live_version_id)->toBe($v3->id)
        ->and(PlaybookVersion::where('playbook_id', $playbook->id)->count())->toBe(3);

    $voice1 = $versions->createVoiceVersion($workspace->id, ['personality' => 'One', 'dont_phrases' => ['Rest assured']]);
    $voice2 = $versions->createVoiceVersion($workspace->id, ['personality' => 'Two']);
    $voice3 = $versions->restoreVoice($voice1);

    expect($voice2->version)->toBe($voice1->version + 1)
        ->and($voice3->personality)->toBe('One')
        ->and($voice3->dont_phrases)->toBe($voice1->dont_phrases)
        ->and(AgentSetting::forWorkspace($workspace->id)->live_voice_version_id)->toBe($voice3->id);
});

test('the legacy import migrates intents, examples with embeddings, live facts and kb documents idempotently', function () {
    // The old tables are dropped by the last migration; recreate them empty
    // (that migration's down()) to stand in for a pre-rework database.
    (require database_path('migrations/2026_12_02_000000_drop_commerce_assist_tables.php'))->down();
    $workspace = Workspace::factory()->create();
    $vector = '['.implode(',', array_fill(0, 1536, '0.01')).']';

    DB::table('commerce_assist_intents')->insert([
        'workspace_id' => $workspace->id, 'slug' => 'order_status', 'name' => 'Order status (edited)',
        'rules' => "- Always quote the order number.\n- If the order is unpaid, ask them to pay.",
        'always_human' => false, 'auto_send_allowed' => false, 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $exampleId = DB::table('commerce_assist_approved_responses')->insertGetId([
        'workspace_id' => $workspace->id, 'customer_message' => 'Where is it?', 'final_reply' => 'On its way!',
        'intent' => 'order_status', 'subtype' => 'order_status.fulfilled', 'source' => 'manual', 'embedding' => $vector,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $scoped = DB::table('commerce_assist_live_facts')->insertGetId([
        'workspace_id' => $workspace->id, 'title' => 'Stock', 'body' => 'Pro is back in stock.',
        'intent_slugs' => json_encode(['product_question']), 'product_keywords' => json_encode(['Pro']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $unscoped = DB::table('commerce_assist_live_facts')->insertGetId([
        'workspace_id' => $workspace->id, 'title' => 'Holiday', 'body' => 'We are closed on Friday.',
        'intent_slugs' => json_encode([]), 'product_keywords' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $retired = DB::table('commerce_assist_live_facts')->insertGetId([
        'workspace_id' => $workspace->id, 'body' => 'Old fact', 'intent_slugs' => json_encode(['other']),
        'retired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $kb = KnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'Policies', 'active' => true]);
    KbDocument::create(['kb_id' => $kb->id, 'title' => 'Returns', 'content' => '<p>'.str_repeat('Returns are accepted within 30 days. ', 120).'</p>']);

    $importer = app(LegacyImporter::class);
    $first = $importer->run($workspace->id);
    $second = $importer->run($workspace->id);

    $playbook = Playbook::with('liveVersion')->where('workspace_id', $workspace->id)->where('slug', 'order_status')->sole();
    expect($playbook->name)->toBe('Order status (edited)')
        ->and(collect($playbook->liveVersion->rules)->pluck('then')->all())->toBe(['Always quote the order number.', 'Ask them to pay.'])
        ->and(collect($playbook->liveVersion->rules)->pluck('if')->all())->toBe(['always', 'the order is unpaid']);

    $example = AgentExample::where('legacy_approved_response_id', $exampleId)->sole();
    expect($example->playbook_slug)->toBe('order_status.fulfilled')
        ->and(DB::table('agent_examples')->where('id', $example->id)->value('embedding'))->toBe(DB::table('commerce_assist_approved_responses')->where('id', $exampleId)->value('embedding'));

    $facts = KnowledgeItem::where('workspace_id', $workspace->id)->where('source_type', 'live_fact')->get()->keyBy('source_id');
    expect($facts[$scoped]->status)->toBe('active')
        ->and($facts[$scoped]->scope['playbooks'])->toBe(['product_question'])
        ->and($facts[$scoped]->scope['products'])->toBe(['pro'])
        ->and($facts[$unscoped]->status)->toBe('needs_scope')
        ->and($facts[$unscoped]->scope['global'])->toBeFalse()
        ->and($facts[$retired]->status)->toBe('retired');

    expect($first['needs_scope'])->toBe(1)
        ->and($first['examples'])->toBe(1)
        ->and($second['examples'])->toBe(0)
        ->and($second['knowledge_facts'])->toBe(0)
        ->and(KnowledgeItem::where('workspace_id', $workspace->id)->where('kind', 'kb_chunk')->count())->toBeGreaterThan(1)
        ->and(AgentExample::where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(Playbook::where('workspace_id', $workspace->id)->count())->toBe(count(require app_path('Domains/Agent/Resources/starter_intents.php')));
});

test('the legacy import is safe on a fresh install with nothing to import', function () {
    expect(app(LegacyImporter::class)->run())->toMatchArray(['workspaces' => 0, 'examples' => 0]);
});

test('kb documents are chunked paragraph-aware with overlap and kept in sync on create, update and delete', function () {
    $workspace = Workspace::factory()->create();
    $kb = KnowledgeBase::create(['workspace_id' => $workspace->id, 'name' => 'Help', 'active' => true]);
    $paragraphs = collect(range(1, 12))->map(fn ($i) => "<p>Paragraph {$i}. ".str_repeat("Detail {$i} sentence here. ", 12).'</p>')->implode('');

    $document = app(KnowledgeBaseService::class)->createDocument($kb, ['title' => 'Shipping guide', 'content' => $paragraphs]);

    $chunks = KnowledgeItem::where('source_document_id', $document->id)->orderBy('chunk_index')->get();
    expect($chunks->count())->toBeGreaterThan(1)
        ->and($chunks->every(fn ($c) => mb_strlen($c->body) <= 1800 + 400))->toBeTrue()
        ->and($chunks->first()->scope['global'])->toBeTrue()
        ->and($chunks->first()->title)->toContain('Shipping guide (part 1 of')
        // Nothing past the first 900 characters was lost.
        ->and($chunks->pluck('body')->implode(' '))->toContain('Paragraph 12.');

    // Overlap: the second chunk starts with text from the end of the first.
    expect($chunks[0]->body)->toContain(mb_substr($chunks[1]->body, 0, 30));

    // A re-scoped chunk keeps its scope through a document edit.
    $chunks->first()->update(['scope' => ['playbooks' => ['order_status'], 'products' => [], 'countries' => [], 'global' => false]]);
    app(KnowledgeBaseService::class)->updateDocument($document, ['title' => 'Shipping guide', 'content' => '<p>Short now.</p>']);

    $after = KnowledgeItem::where('source_document_id', $document->id)->get();
    expect($after)->toHaveCount(1)
        ->and($after->first()->body)->toBe('Short now.')
        ->and($after->first()->scope['playbooks'])->toBe(['order_status']);

    app(KnowledgeBaseService::class)->deleteDocument($document);
    expect(KnowledgeItem::where('source_document_id', $document->id)->count())->toBe(0);
});

test('the chunker splits an over-long paragraph on sentences', function () {
    $chunks = app(KnowledgeChunker::class)->chunk(str_repeat('This is one sentence. ', 300), 500, 80);

    expect(count($chunks))->toBeGreaterThan(5)
        ->and(collect($chunks)->every(fn ($c) => mb_strlen($c) <= 500 + 80 + 2))->toBeTrue();

    app(KbKnowledgeSync::class); // resolves
});
