<?php

namespace App\Console\Commands;

use App\Models\SheetTab;
use App\Services\ImportWorkflowService;
use Illuminate\Console\Command;

class ImportGoogleSheets extends Command
{
    protected $signature = 'sheets:import';

    protected $description = 'Import data from all active Google Sheets';

    public function handle(ImportWorkflowService $workflow): int
    {
        $tabs = SheetTab::query()
            ->where('is_active', true)
            ->whereHas('googleSheet', function ($query) {
                $query->where('is_active', true);
            })
            ->with('googleSheet')
            ->get();

        if ($tabs->isEmpty()) {
            $this->warn('No active Google Sheets tabs found.');

            return self::SUCCESS;
        }

        $this->info("Found {$tabs->count()} active tabs.");

        $hasFailures = false;

        foreach ($tabs as $tab) {
            $this->newLine();

            $this->info(
                "Importing: {$tab->googleSheet->name} / {$tab->tab_name}"
            );

            $result = $workflow->importTab($tab);

            $this->line("Status: {$result['status']}");
            $this->line("Rows read: {$result['rows_read']}");
            $this->line("Records inserted: {$result['rows_imported']}");
            $this->line("Rows failed: {$result['rows_failed']}");
            $this->line("Log ID: {$result['log_id']}");

            if ($result['status'] !== 'success') {
                $hasFailures = true;

                foreach ($result['errors'] as $error) {
                    $this->error(
                        is_string($error)
                            ? $error
                            : json_encode($error, JSON_UNESCAPED_UNICODE)
                    );
                }
            }
        }

        $this->newLine();

        if ($hasFailures) {
            $this->warn('Import finished with failures or partial results.');

            return self::FAILURE;
        }

        $this->info('All imports completed successfully.');

        return self::SUCCESS;
    }
}
