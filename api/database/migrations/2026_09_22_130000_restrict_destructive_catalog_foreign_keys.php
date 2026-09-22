<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a catalog row must never destroy the stock and device rows filed under it.
 *
 * Verified against MySQL 8.0 before this change:
 *
 *   devices.category_id             ON DELETE CASCADE
 *   brands.category_id              ON DELETE CASCADE
 *   inventory_batches.category_id   ON DELETE CASCADE
 *   inventory_movements.category_id ON DELETE SET NULL
 *   product_models.brand_id         ON DELETE CASCADE
 *
 * So one hard DELETE of a `device_categories` row took every device *and* every
 * inventory batch in that category with it, and nulled the ledger's category
 * attribution. `DeviceCategoryController::destroy` only guarded on devices, so the
 * batch wipe was not covered by application code at all.
 *
 * Soft deletes never reach a foreign key - `$category->delete()` is an UPDATE - so the
 * cascade was dormant in the normal path. It was still reachable via `forceDelete()`,
 * a raw DELETE, or any future endpoint: exactly the situation the branch equivalent
 * (2026_09_22_110000) fixed. RESTRICT moves the guarantee into the schema, so an
 * unreviewed delete fails loudly (SQLSTATE 1451) instead of silently destroying rows.
 *
 * Deliberately NOT changed: `devices.brand_id` and `devices.product_model_id` are
 * ON DELETE SET NULL **on purpose** - the catalog deletion override detaches devices
 * from a deleted brand/model rather than deleting them, and the impact preview reports
 * that to the operator. Only the keys that destroy a row or erase its attribution are
 * rebuilt here.
 *
 * SQLite cannot drop foreign keys, so this is a MySQL-only change, following the
 * existing convention in this repo (see 2026_09_22_110000_*).
 */
return new class extends Migration
{
    /**
     * Child table => [column => parent table] for every catalog foreign key that
     * would destroy a row (CASCADE) or erase its attribution (SET NULL).
     *
     * @var array<string, array<string, string>>
     */
    private const DESTRUCTIVE = [
        'devices' => ['category_id' => 'device_categories'],
        'brands' => ['category_id' => 'device_categories'],
        'inventory_batches' => ['category_id' => 'device_categories'],
        'inventory_movements' => ['category_id' => 'device_categories'],
        'product_models' => ['brand_id' => 'brands'],
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
     * Drop and re-create the catalog foreign keys with the requested rule.
     *
     * With $restrict every key becomes ON DELETE RESTRICT. Without it the original
     * rules are restored: SET NULL on the ledger, CASCADE everywhere else.
     */
    private function rebuildForeignKeys(bool $restrict): void
    {
        foreach (self::DESTRUCTIVE as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach (array_keys($columns) as $column) {
                    $blueprint->dropForeign("{$table}_{$column}_foreign");
                }
            });

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns, $restrict) {
                foreach ($columns as $column => $parent) {
                    $foreign = $blueprint->foreign($column)->references('id')->on($parent);

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
