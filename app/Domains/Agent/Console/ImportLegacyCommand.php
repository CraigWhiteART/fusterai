<?php

namespace App\Domains\Agent\Console;

use App\Domains\Agent\Services\LegacyImporter;
use App\Domains\Agent\Services\LegacyTables;
use Illuminate\Console\Command;

class ImportLegacyCommand extends Command
{
    protected $signature = 'agent:import-legacy {--workspace= : Only this workspace id} {--embed : Embed new knowledge chunks when embeddings are configured} {--drop-old-tables : After importing, drop the old commerce_assist_* tables if nothing is left un-imported}';

    protected $description = 'Idempotently import Commerce Assist intents, approved responses, live facts and KB documents into the agent tables';

    public function handle(LegacyImporter $importer): int
    {
        $workspace = $this->option('workspace');
        $counts = $importer->run($workspace !== null ? (int) $workspace : null, (bool) $this->option('embed'));

        foreach ($counts as $key => $value) {
            $this->line(str_pad($key, 18).$value);
        }

        if ($counts['needs_scope'] > 0) {
            $this->warn("{$counts['needs_scope']} knowledge item(s) need a scope before the agent uses them (Agent > Knowledge > Needs scope).");
        }

        if ($this->option('drop-old-tables')) {
            if (LegacyTables::dropped()) {
                $this->info('The old Commerce Assist tables are already gone.');
            } elseif (($missing = LegacyTables::dropIfImported()) === []) {
                $this->info('Dropped the old Commerce Assist tables.');
            } else {
                $this->error('Not dropped: rows were never imported (workspace => kind => count): '.json_encode($missing));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
