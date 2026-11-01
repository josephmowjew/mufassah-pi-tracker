<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\InventoryBatch;
use App\Models\ProductModel;

/**
 * Carries an existing price forward into a new intake.
 *
 * Price in this system belongs to the batch (the lot), not to the product, and
 * every intake creates a new batch. That is deliberate — each delivery has its
 * own purchase cost and therefore its own realised margin — but it means the
 * selling price has to be re-typed on every receipt even when the product has
 * not been repriced. This resolver answers "what did we last sell this product
 * for?" so intake can inherit it instead of asking again.
 *
 * Resolution order:
 *   1. the newest non-deleted batch for the same product that still carries a
 *      positive target_selling_price — i.e. the price actually in use. A batch
 *      in the receiving branch wins over one elsewhere, since branches may
 *      price the same product differently;
 *   2. the catalog's ProductModel.base_price;
 *   3. nothing. The caller must then supply a price or reject the line; this
 *      class never invents one.
 *
 * Matching prefers `inventory_batches.product_model_id` and only falls back to
 * the text fields (`category_id` + `brand` + `model`) for rows written before
 * that column existed. Text matching is what a catalog rename breaks.
 *
 * The buying price is only ever *reported*, never assumed: purchase cost is
 * real data that changes with every delivery and drives every margin figure.
 */
class InventoryPriceResolver
{
    /**
     * @return array{
     *     buying_price: float|null,
     *     target_selling_price: float|null,
     *     source: string|null,
     *     batch_id: int|null,
     *     batch_number: string|null,
     *     received_at: string|null
     * }
     */
    public function resolve(int $categoryId, string $brand, string $model, ?int $branchId = null): array
    {
        $catalogModel = $this->catalogModel($categoryId, $brand, $model);

        $batch = $this->latestPricedBatch($categoryId, $brand, $model, $branchId, $catalogModel?->id);

        if ($batch) {
            return [
                'buying_price' => $batch->buying_price !== null ? round((float) $batch->buying_price, 2) : null,
                'target_selling_price' => round((float) $batch->target_selling_price, 2),
                'source' => 'last_batch',
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'received_at' => $batch->created_at?->toLocalIso8601String(),
            ];
        }

        $catalogPrice = $this->catalogBasePrice($catalogModel);

        return [
            'buying_price' => null,
            'target_selling_price' => $catalogPrice,
            'source' => $catalogPrice !== null ? 'catalog_base_price' : null,
            'batch_id' => null,
            'batch_number' => null,
            'received_at' => null,
        ];
    }

    /**
     * The price a new intake should inherit, or null when there is nothing
     * sensible to inherit.
     */
    public function fallbackSellingPrice(int $categoryId, string $brand, string $model, ?int $branchId = null): ?float
    {
        return $this->resolve($categoryId, $brand, $model, $branchId)['target_selling_price'];
    }

    private function latestPricedBatch(
        int $categoryId,
        string $brand,
        string $model,
        ?int $branchId,
        ?int $productModelId,
    ): ?InventoryBatch {
        $newestPriced = InventoryBatch::query()
            ->where('target_selling_price', '>', 0)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($productModelId !== null) {
            $byModel = $this->preferBranch(
                (clone $newestPriced)->where('product_model_id', $productModelId),
                $branchId,
            );

            if ($byModel) {
                return $byModel;
            }
        }

        $normalizedBrand = $this->normalize($brand);
        $normalizedModel = $this->normalize($model);

        if ($normalizedBrand === '' || $normalizedModel === '') {
            return null;
        }

        // Legacy rows predate product_model_id, so a canonical spelling is not
        // guaranteed; match case/whitespace-insensitively.
        $byText = (clone $newestPriced)
            ->where('category_id', $categoryId)
            ->where(function ($query) use ($brand, $normalizedBrand) {
                $query->where('brand', $brand)
                    ->orWhereRaw('LOWER(TRIM(brand)) = ?', [$normalizedBrand]);
            })
            ->where(function ($query) use ($model, $normalizedModel) {
                $query->where('model', $model)
                    ->orWhereRaw('LOWER(TRIM(model)) = ?', [$normalizedModel]);
            });

        return $this->preferBranch($byText, $branchId);
    }

    private function preferBranch($query, ?int $branchId): ?InventoryBatch
    {
        if ($branchId) {
            $scoped = (clone $query)->where('branch_id', $branchId)->first();

            if ($scoped) {
                return $scoped;
            }
        }

        return $query->first();
    }

    private function catalogModel(int $categoryId, string $brand, string $model): ?ProductModel
    {
        $brandId = Brand::query()
            ->where('category_id', $categoryId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$this->normalize($brand)])
            ->value('id');

        if (! $brandId) {
            return null;
        }

        return ProductModel::query()
            ->where('brand_id', $brandId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$this->normalize($model)])
            ->first();
    }

    private function catalogBasePrice(?ProductModel $catalogModel): ?float
    {
        $price = $catalogModel?->base_price;

        if ($price === null || (float) $price <= 0) {
            return null;
        }

        return round((float) $price, 2);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
