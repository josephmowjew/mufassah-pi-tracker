<?php

namespace App\Services;

use App\Models\Shift;
use Illuminate\Support\Facades\DB;

class ShiftClosingService
{
    public function closeIfDue(Shift $shift): bool
    {
        if (! $shift->isDueToEnd(now())) {
            return false;
        }

        return DB::transaction(function () use ($shift) {
            $locked = Shift::query()->active()->lockForUpdate()->find($shift->id);
            if (! $locked || ! $locked->isDueToEnd(now())) {
                return false;
            }

            $locked->complete(['ended_at' => $locked->automaticEndAt()]);

            return true;
        });
    }
}
