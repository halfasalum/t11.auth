<?php

namespace App\Console\Commands;

use App\Models\Loans;
use App\Models\ServicesModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateOverdueLoans extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-overdue-loans 
                            {--chunk=500 : Number of records to process per chunk}
                            {--dry-run : Run without making changes}
                            {--force : Force execution even in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update loans that are overdue by setting their status to 12';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = microtime(true);
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');
        
        $this->info('Starting overdue loans update process...');
        $this->info("Mode: " . ($dryRun ? 'DRY RUN (no changes)' : 'LIVE UPDATE'));
        $this->info("Chunk size: {$chunkSize}");
        
        // Log start of execution
        Log::info('UpdateOverdueLoans command started', [
            'dry_run' => $dryRun,
            'chunk_size' => $chunkSize,
            'executed_at' => now()
        ]);

        try {
            DB::beginTransaction();
            
            $today = now()->toDateString();
            
            // Get count of overdue loans first
            $totalCount = Loans::where('end_date', '<=', $today)
                ->where('status', 5)
                ->count();
            
            $this->info("Total overdue loans found: {$totalCount}");
            Log::info('Overdue loans found', ['count' => $totalCount]);
            
            if ($totalCount === 0) {
                $this->warn("No overdue loans to update.");
                $this->updateServiceLastRun();
                return 0;
            }
            
            if ($dryRun) {
                // Just show what would be updated without making changes
                $this->displayDryRunInfo($today);
                $this->updateServiceLastRun();
                return 0;
            }
            
            // Option 1: Bulk update (faster for simple updates)
            if ($chunkSize === 0) {
                $updated = Loans::where('end_date', '<=', $today)
                    ->where('status', 5)
                    ->update([
                        'status' => 12,
                        //'is_defaulted' => 1,
                        'updated_at' => now()
                    ]);
                
                $this->info("Updated {$updated} overdue loans using bulk update.");
                Log::info('Bulk update completed', ['updated_count' => $updated]);
            } 
            // Option 2: Chunked update (better for large datasets with complex operations)
            else {
                $updated = $this->processInChunks($today, $chunkSize);
                $this->info("Updated {$updated} overdue loans using chunked update.");
                Log::info('Chunked update completed', ['updated_count' => $updated]);
            }
            
            // Validate the update
            $remainingCount = Loans::where('end_date', '<=', $today)
                ->where('status', 5)
                ->count();
            
            if ($remainingCount > 0) {
                $this->warn("Warning: {$remainingCount} loans still have status 5 but are overdue.");
                Log::warning('Some overdue loans were not updated', ['remaining' => $remainingCount]);
            }
            
            DB::commit();
            
            $executionTime = round(microtime(true) - $startTime, 2);
            $this->info("Process completed in {$executionTime} seconds.");
            Log::info('UpdateOverdueLoans command completed', [
                'execution_time_seconds' => $executionTime,
                'updated_count' => $updated ?? 0
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error updating overdue loans: " . $e->getMessage());
            Log::error('UpdateOverdueLoans command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1;
        }
        
        $this->updateServiceLastRun();
        
        return 0;
    }
    
    /**
     * Process updates in chunks to avoid memory issues
     *
     * @param string $today
     * @param int $chunkSize
     * @return int Total updated count
     */
    private function processInChunks(string $today, int $chunkSize): int
    {
        $totalUpdated = 0;
        $processedLoans = 0;
        
        // Add indexes to ensure we process all records
        $query = Loans::where('end_date', '<=', $today)
            ->where('status', 5)
            ->orderBy('id');
        
        $totalToProcess = $query->count();
        $this->info("Processing {$totalToProcess} loans in chunks of {$chunkSize}");
        
        $query->chunkById($chunkSize, function ($loans) use (&$totalUpdated, &$processedLoans, $chunkSize) {
            $ids = $loans->pluck('id')->toArray();
            
            $updated = Loans::whereIn('id', $ids)
                ->update([
                    'status' => 12,
                    'is_defaulted' => 1,
                    'updated_at' => now()
                ]);
            
            $totalUpdated += $updated;
            $processedLoans += count($ids);
            
            $this->line("Processed chunk: {$processedLoans}/{$totalUpdated} updated");
            
            // Optional: Add a small delay to prevent database overload
            if ($this->option('chunk') > 0) {
                usleep(10000); // 10ms delay between chunks
            }
        });
        
        return $totalUpdated;
    }
    
    /**
     * Display information about what would be updated in dry-run mode
     *
     * @param string $today
     */
    private function displayDryRunInfo(string $today): void
    {
        $this->info("DRY RUN - Would update the following:");
        
        $overdueLoans = Loans::where('end_date', '<=', $today)
            ->where('status', 5)
            ->select('id', 'end_date', 'status')
            ->limit(10)
            ->get();
        
        if ($overdueLoans->isNotEmpty()) {
            $this->table(
                ['Loan ID', 'End Date', 'Current Status'],
                $overdueLoans->map(function ($loan) {
                    return [$loan->id, $loan->end_date, $loan->status];
                })
            );
            
            $totalCount = Loans::where('end_date', '<=', $today)
                ->where('status', 5)
                ->count();
            
            if ($totalCount > 10) {
                $this->line("... and " . ($totalCount - 10) . " more loans");
            }
        } else {
            $this->line("No loans would be updated.");
        }
    }
    
    /**
     * Update the service last run timestamp
     */
    private function updateServiceLastRun(): void
    {
        try {
            ServicesModel::where('id', 2)
                ->update(['last_run' => now()]);
            $this->line("Service last_run timestamp updated.");
        } catch (\Exception $e) {
            $this->warn("Failed to update service last_run: " . $e->getMessage());
            Log::warning('Failed to update service last_run', ['error' => $e->getMessage()]);
        }
    }
}