<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batches identified their product by free text only.
 *
 * `inventory_batches` stored `category_id` + `brand` + `model` as strings, so
 * every lookup that needed "the other batches for this product" — the intake
 * price fallback, the catalog price cascade — matched on text. That breaks the
 * moment a catalog model is renamed, and it silently misses rows written before
 * `InventoryCatalogResolver` started normalising the spelling.
 *
 * `devices` already carries `product_model_id`; batches were the odd one out.
 * The column is nullable and SET NULL on delete, matching `devices`: deleting a
 * catalog model must detach stock, never destroy the batch and its cost history
 * (see 2026_09_22_130000 for the same reasoning).
 *
 * Existing rows are backfilled best-effort by joining the catalog on the text
 * fields. Anything that cannot be matched stays null and the readers fall back
 * to text matching, so a partial backfill is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->foreignId('product_model_id')
                ->nullable()
                ->after('category_id')
                ->constrained('product_models')
                ->nullOnDelete();

            // The price fallback asks for the newest batch of one model.
            $table->index(['product_model_id', 'created_at']);
        });

        $this->backfillProductModelIds();
    }

    public function down(): void
    {
        // SQLite cannot drop a foreign key, and this repo's convention is to
        // skip FK surgery there (see 2026_09_22_130000). Dropping the column
        // takes its indexes with it.
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('inventory_batches', function (Blueprint $table) {
                $table->dropColumn('product_model_id');
            });

            return;
        }

        Schema::table('inventory_batches', function (Blueprint $table) {
            // MySQL refuses to drop an index the foreign key still depends on
            // (SQLSTATE 1553), so the constraint has to go first.
            $table->dropForeign(['product_model_id']);
            $table->dropIndex(['product_model_id', 'created_at']);
        });

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn('product_model_id');
        });
    }

    private function backfillProductModelIds(): void
    {
        DB::table('inventory_batches')
            ->select(['id', 'category_id', 'brand', 'model'])
            ->whereNull('product_model_id')
            ->chunkById(500, function ($batches) {
                foreach ($batches as $batch) {
                    $modelId = $this->findCatalogModelId($batch);

                    if ($modelId !== null) {
                        DB::table('inventory_batches')
                            ->where('id', $batch->id)
                            ->update(['product_model_id' => $modelId]);
                    }
                }
            });
    }

    /**
     * Match the batch's text identity to a catalog model. Soft-deleted catalog
     * rows are included on purpose: a deleted model still owns historical
     * batches and the link is worth keeping.
     */
    private function findCatalogModelId(object $batch): ?int
    {
        $modelId = DB::table('product_models')
            ->join('brands', 'brands.id', '=', 'product_models.brand_id')
            ->where('brands.category_id', $batch->category_id)
            ->whereRaw('LOWER(TRIM(brands.name)) = ?', [mb_strtolower(trim((string) $batch->brand))])
            ->whereRaw('LOWER(TRIM(product_models.name)) = ?', [mb_strtolower(trim((string) $batch->model))])
            ->value('product_models.id');

        return $modelId !== null ? (int) $modelId : null;
    }
};
