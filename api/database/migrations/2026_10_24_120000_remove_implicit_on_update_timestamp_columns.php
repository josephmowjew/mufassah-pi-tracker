<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stops MariaDB from rewriting historical timestamps whenever a row is updated.
 *
 * PROD runs MariaDB 10.6 with explicit_defaults_for_timestamp=0. Under that
 * setting the *first* non-nullable TIMESTAMP column in a table that has no
 * explicit DEFAULT is given:
 *
 *     DEFAULT current_timestamp() ON UPDATE current_timestamp()
 *
 * Laravel's `$table->timestamp('x')` emits exactly `timestamp not null` with no
 * DEFAULT, so six columns silently acquired that ON UPDATE clause:
 *
 *   shifts.started_at                      -> every shift reported 0 seconds
 *   inventory_action_challenges.expires_at -> PIN expiry slid forward on every
 *                                             update, so challenges never aged out
 *   device_locations.recorded_at
 *   device_ownership_history.transferred_at
 *   geofence_events.event_time
 *   theft_reports.theft_date
 *
 * The damage is easiest to see in shifts: completing a shift updates the row,
 * which reset started_at to the same instant as ended_at. All five completed
 * shifts read 0 seconds, and the sale booked at 11:33 belonged to a shift whose
 * recorded start was 15:01 - the real start had already been overwritten.
 *
 * DATETIME has no implicit zero-date default and no implicit ON UPDATE, so
 * converting to it removes the trigger while keeping the stored values. Laravel
 * casts these columns to 'datetime' already, so readers are unaffected.
 *
 * SQLite stores no such clause, so the change is skipped there (repo convention,
 * see 2026_09_22_130000).
 */
return new class extends Migration
{
    /**
     * Columns whose value must survive updates, with their nullability.
     *
     * @var array<string, array<string, bool>>
     */
    private const COLUMNS = [
        'shifts' => ['started_at' => false],
        'inventory_action_challenges' => ['expires_at' => false],
        'device_locations' => ['recorded_at' => false],
        'device_ownership_history' => ['transferred_at' => false],
        'geofence_events' => ['event_time' => false],
        'theft_reports' => ['theft_date' => false],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $this->convertColumns('datetime');
        $this->backfillShiftStarts();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Rolling back restores the original column type. On MariaDB with
        // explicit_defaults_for_timestamp=0 that re-arms the ON UPDATE clause,
        // which is exactly the defect this migration removes - roll back only
        // if you accept the corruption returning.
        $this->convertColumns('timestamp');
    }

    /**
     * Rewrite the affected columns to the given type, preserving nullability.
     */
    private function convertColumns(string $type): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $type) {
                foreach ($columns as $column => $nullable) {
                    $definition = $type === 'datetime'
                        ? $blueprint->dateTime($column)
                        : $blueprint->timestamp($column);

                    if ($nullable) {
                        $definition->nullable();
                    }

                    $definition->change();
                }
            });
        }
    }

    /**
     * Recover a lower bound for start times the defect already destroyed.
     *
     * Only completed shifts whose started_at equals ended_at are candidates:
     * that is the signature of the ON UPDATE clause firing during completion.
     * The earliest sale booked against the shift is used as the start, which is
     * an approximation - the true start may be earlier - but it is strictly
     * better than a zero-second shift and is not presented as measured data.
     *
     * Shifts with no sales are left untouched: there is nothing to infer from,
     * and inventing a value would be worse than an honest zero.
     */
    private function backfillShiftStarts(): void
    {
        DB::table('shifts')
            ->whereNotNull('ended_at')
            ->whereColumn('started_at', 'ended_at')
            ->orderBy('id')
            ->chunkById(200, function ($shifts) {
                foreach ($shifts as $shift) {
                    $firstSale = DB::table('sales')
                        ->where('shift_id', $shift->id)
                        ->min('created_at');

                    if ($firstSale === null) {
                        continue;
                    }

                    if (strtotime((string) $firstSale) >= strtotime((string) $shift->ended_at)) {
                        continue;
                    }

                    DB::table('shifts')
                        ->where('id', $shift->id)
                        ->update(['started_at' => $firstSale]);
                }
            });
    }
};
