<?php

namespace App\Console\Commands;

use App\Models\Shift;
use App\Services\ShiftClosingService;
use Illuminate\Console\Command;

class CloseExpiredShifts extends Command
{
    protected $signature = 'shifts:close-expired';

    protected $description = 'Close active shifts that reached their automatic end time';

    public function handle(ShiftClosingService $closing): int
    {
        $count = 0;
        Shift::query()->active()->eachById(function (Shift $shift) use ($closing, &$count) {
            if ($closing->closeIfDue($shift)) {
                $count++;
            }
        });

        $this->info("Closed {$count} expired shift(s).");

        return self::SUCCESS;
    }
}
