<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a branch must never destroy inventory or attendance records.
 *
 * `inventory_batches.branch_id`, `inventory_receipts.branch_id` and
 * `shifts.branch_id` were ON DELETE CASCADE. Verified against MySQL 8.0: deleting
 * a single branch took its batches/receipts/shifts from 1/1/1 to 0/0/0 — a silent,
 * irreversible loss of the stock and attendance audit trail.
 *
 * `BranchController::destroy` pre-checks linked records, so that cascade was
 * dormant rather than live. But the guard is application code: it does not protect
 * a tinker session, a future endpoint, or a `forceDelete()`. RESTRICT moves the
 * guarantee into the schema, so an unreviewed delete fails loudly (SQLSTATE 1451)
 * instead of silently destroying data.
 *
 * `inventory_movements.*_branch_id` were ON DELETE SET NULL, which preserved the
 * ledger row but erased its branch attribution — an audit hole rather than data
 * loss. Those become RESTRICT too.
 *
 * SQLite cannot drop foreign keys, so this is a MySQL-only change. That follows
 * the existing convention in this repo (see 2026_09_12_120000_* and
 * 2026_02_24_074815_*).
 */
return new class extends Migration
{
    /**
     * Destructive branch foreign keys to replace, keyed by table.
     *
     * @var array<string, list<string>>
     */
    private const DESTRUCTIVE = [
        'inventory_batches' => ['branch_id'],
        'inventory_receipts' => ['branch_id'],
        'shifts' => ['branch_id'],
        'inventory_movements' => ['branch_id', 'from_branch_id', 'to_branch_id'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $this->rebuildForeignKeys(restrict: true);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $this->rebuildForeignKeys(restrict: false);
    }

    /**
     * Drop and re-create the branch foreign keys with the requested rule.
     *
     * With $restrict, every key becomes ON DELETE RESTRICT. Without it, the
     * original rules are restored: CASCADE on the stock/attendance tables and
     * SET NULL on the ledger.
     */
    private function rebuildForeignKeys(bool $restrict): void
    {
        foreach (self::DESTRUCTIVE as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    $blueprint->dropForeign("{$table}_{$column}_foreign");
                }
            });

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns, $restrict) {
                foreach ($columns as $column) {
                    $foreign = $blueprint->foreign($column)->references('id')->on('branches');

                    if ($restrict) {
                        $foreign->restrictOnDelete();
                    } elseif ($table === 'inventory_movements') {
                        $foreign->nullOnDelete();
                    } else {
                        $foreign->cascadeOnDelete();
                    }
                }
            });
        }
    }
};
