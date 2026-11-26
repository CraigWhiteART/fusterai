<?php

use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Services\LegacyImporter;
use App\Domains\Agent\Services\LegacyTables;
use App\Domains\AI\Models\Module;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * The last step of removing Modules/CommerceAssist: the old tables are
 * dropped only once every row has its agent_* copy (LegacyTables, migration
 * 2026_12_02_000000_drop_commerce_assist_tables).
 *
 * "Pre-rework database": the old tables are recreated (that migration's
 * down()) and filled with realistic rows for two workspaces, the old
 * `modules` row is present, and nothing has been imported yet.
 */

function legacyTMigration(): object
{
    return require database_path('migrations/2026_12_02_000000_drop_commerce_assist_tables.php');
}

/** @return array{a: Workspace, b: Workspace} */
function legacyTPreReworkData(): array
{
    legacyTMigration()->down();
    Module::firstOrCreate(['alias' => 'CommerceAssist'], ['name' => 'Commerce Assist', 'version' => '1.0.0', 'active' => true, 'config' => []]);

    $out = [];
    foreach (['a', 'b'] as $key) {
        $ws = Workspace::factory()->create();
        $admin = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'admin']);
        $mailbox = Mailbox::factory()->create(['workspace_id' => $ws->id]);
        $customer = Customer::factory()->create(['workspace_id' => $ws->id]);
        $conversation = Conversation::factory()->create(['workspace_id' => $ws->id, 'mailbox_id' => $mailbox->id, 'customer_id' => $customer->id]);

        DB::table('commerce_assist_intents')->insert([
            'workspace_id' => $ws->id, 'slug' => 'order_status', 'name' => 'Order status', 'rules' => "- Quote the order number.\n- Never promise a date.",
            'always_human' => false, 'auto_send_allowed' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $generation = DB::table('commerce_assist_generations')->insertGetId([
            'workspace_id' => $ws->id, 'conversation_id' => $conversation->id, 'ai_draft' => 'Draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(1, 3) as $i) {
            DB::table('commerce_assist_approved_responses')->insert([
                'workspace_id' => $ws->id, 'conversation_id' => $conversation->id, 'generation_id' => $generation, 'customer_message' => "Question {$i}",
                'final_reply' => "Answer {$i}", 'intent' => 'order_status', 'source' => 'manual', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('commerce_assist_live_facts')->insert([
            ['workspace_id' => $ws->id, 'title' => 'Stock', 'body' => 'Pro is back in stock.', 'intent_slugs' => json_encode(['product_question']), 'product_keywords' => json_encode(['Pro']), 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $ws->id, 'title' => 'Closed', 'body' => 'Closed Friday.', 'intent_slugs' => json_encode([]), 'product_keywords' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('commerce_assist_replay_runs')->insert(['workspace_id' => $ws->id, 'conversation_id' => $conversation->id, 'generation_id' => $generation, 'created_at' => now(), 'updated_at' => now()]);
        $scan = DB::table('commerce_assist_history_scans')->insertGetId(['workspace_id' => $ws->id, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('commerce_assist_history_learnings')->insert(['workspace_id' => $ws->id, 'scan_id' => $scan, 'kind' => 'fact', 'created_at' => now(), 'updated_at' => now()]);

        $out[$key] = $ws;
    }

    return $out;
}

test('on a pre-rework database the migration imports everything, then drops the old tables', function () {
    $ws = legacyTPreReworkData();
    expect(LegacyTables::unimported())->toHaveCount(2);

    legacyTMigration()->up();

    foreach (LegacyTables::TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    foreach ($ws as $workspace) {
        expect(AgentExample::where('workspace_id', $workspace->id)->count())->toBe(3)
            ->and(KnowledgeItem::where('workspace_id', $workspace->id)->where('source_type', 'live_fact')->count())->toBe(2)
            ->and(Playbook::where('workspace_id', $workspace->id)->where('slug', 'order_status')->exists())->toBeTrue();
    }
    expect(Module::where('alias', 'CommerceAssist')->exists())->toBeFalse()
        // The live commerce tables (renamed earlier) are untouched.
        ->and(Schema::hasTable('commerce_settings'))->toBeTrue()
        ->and(Schema::hasTable('commerce_shopify_snapshots'))->toBeTrue()
        ->and(Schema::hasTable('commerce_tracking_snapshots'))->toBeTrue();

    // Re-running is a no-op.
    legacyTMigration()->up();
});

test('the migration refuses to drop, and logs loudly, while any row is un-imported', function () {
    legacyTPreReworkData();
    // An importer that fails to copy anything (e.g. it crashed on one workspace).
    app()->instance(LegacyImporter::class, Mockery::mock(LegacyImporter::class, fn ($m) => $m->shouldReceive('run')->andReturn([])));
    Log::spy();

    legacyTMigration()->up();

    foreach (LegacyTables::TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
    expect(DB::table('commerce_assist_approved_responses')->count())->toBe(6)
        ->and(Module::where('alias', 'CommerceAssist')->exists())->toBeTrue();
    Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context) => str_contains($message, 'NOT dropped')
        && count($context['unimported']) === 2
        && collect($context['unimported'])->every(fn ($kinds) => $kinds === ['approved_responses' => 3, 'live_facts' => 2, 'intents' => 1]));
});

test('one un-imported row in one workspace is enough to stop the drop', function () {
    $ws = legacyTPreReworkData();
    app(LegacyImporter::class)->run();
    DB::table('commerce_assist_approved_responses')->insert([
        'workspace_id' => $ws['b']->id, 'customer_message' => 'Late', 'final_reply' => 'Added after the import', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(LegacyTables::unimported())->toBe([$ws['b']->id => ['approved_responses' => 1]])
        ->and(LegacyTables::dropIfImported())->not->toBe([])
        ->and(Schema::hasTable('commerce_assist_approved_responses'))->toBeTrue();
});

test('the import command can retry the drop once the import is complete', function () {
    legacyTPreReworkData();

    $this->artisan('agent:import-legacy --drop-old-tables')->expectsOutputToContain('Dropped the old Commerce Assist tables.')->assertSuccessful();

    expect(LegacyTables::dropped())->toBeTrue();
    $this->artisan('agent:import-legacy --drop-old-tables')->expectsOutputToContain('already gone')->assertSuccessful();
});

test('a fresh install has no old tables and no Commerce Assist module row', function () {
    expect(LegacyTables::dropped())->toBeTrue()
        ->and(Module::where('alias', 'CommerceAssist')->exists())->toBeFalse()
        ->and(LegacyTables::unimported())->toBe([]);
});
