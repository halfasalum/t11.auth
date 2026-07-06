<?php

namespace App\Console\Commands;

use App\Services\Kikoba\KikobaPenaltyService;
use Illuminate\Console\Command;

class KikobaDetectOverduePenalties extends Command
{
    protected $signature = 'kikoba:detect-penalties';

    protected $description = 'Scan Kikoba contribution schedules for missed due dates and raise penalties';

    public function handle(KikobaPenaltyService $penaltyService): int
    {
        $this->info('Scanning for overdue Kikoba contribution schedules...');

        $count = $penaltyService->detectAndApplyPenalties();

        $this->info("Done. {$count} penalty record(s) created.");

        return self::SUCCESS;
    }
}
