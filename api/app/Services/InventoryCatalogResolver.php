<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\DeviceCategory;
use App\Models\ProductModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Keeps inventory's human-readable product identity linked to the catalog.
 *
 * Inventory intake historically accepted free-text brands and models. This
 * resolver preserves that workflow while creating the category-scoped catalog
 * entries needed for catalog search, pricing, and reporting.
 */
class InventoryCatalogResolver
{
    /**
     * @return array{brand_id: int, product_model_id: int, brand: string, model: string}
     */
    public function resolve(int $categoryId, string $brandName, string $modelName): array
    {
        $category = DeviceCategory::query()->findOrFail($categoryId);
        $brandInput = $this->cleanName($brandName);
        $modelInput = $this->cleanName($modelName);

        $brand = $this->findBrand($categoryId, $brandInput);
        if (! $brand) {
            $brand = $this->createBrand($category, $brandInput);
        }

        $model = $this->findModel($brand->id, $modelInput);
        if (! $model) {
            $model = $this->createModel($brand, $modelInput);
        }

        return [
            'brand_id' => (int) $brand->id,
            'product_model_id' => (int) $model->id,
            // Use the catalog's canonical spelling in the device and batch
            // text fields so legacy text-based counts remain accurate.
            'brand' => $brand->name,
            'model' => $model->name,
        ];
    }

    private function findBrand(int $categoryId, string $name): ?Brand
    {
        return Brand::query()
            ->where('category_id', $categoryId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$this->normalizedName($name)])
            ->first();
    }

    private function findModel(int $brandId, string $name): ?ProductModel
    {
        return ProductModel::query()
            ->where('brand_id', $brandId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$this->normalizedName($name)])
            ->first();
    }

    private function createBrand(DeviceCategory $category, string $name): Brand
    {
        $attributes = [
            'category_id' => $category->id,
            'name' => $name,
        ];

        try {
            return Brand::query()->firstOrCreate(
                $attributes,
                [
                    'slug' => $this->uniqueBrandSlug($name, $category),
                    'description' => 'Automatically added from inventory intake.',
                    'is_active' => true,
                    'sort_order' => ((int) (Brand::query()->where('category_id', $category->id)->max('sort_order'))) + 1,
                ]
            );
        } catch (QueryException $exception) {
            // A concurrent receipt may have created the same category/name
            // between the lookup and insert. Re-read the canonical record.
            $brand = $this->findBrand($category->id, $name);
            if ($brand) {
                return $brand;
            }

            throw $exception;
        }
    }

    private function createModel(Brand $brand, string $name): ProductModel
    {
        try {
            return ProductModel::query()->firstOrCreate(
                [
                    'brand_id' => $brand->id,
                    'name' => $name,
                ],
                [
                    'slug' => $this->uniqueModelSlug($name, $brand->id),
                    'description' => 'Automatically added from inventory intake.',
                    'is_active' => true,
                    'sort_order' => ((int) (ProductModel::query()->where('brand_id', $brand->id)->max('sort_order'))) + 1,
                ]
            );
        } catch (QueryException $exception) {
            $model = $this->findModel($brand->id, $name);
            if ($model) {
                return $model;
            }

            throw $exception;
        }
    }

    private function uniqueBrandSlug(string $name, DeviceCategory $category): string
    {
        $base = Str::slug($name.'-'.$category->slug);
        if ($base === '') {
            $base = 'brand-'.$category->id;
        }

        return $this->nextAvailableSlug($base, fn (string $slug): bool => Brand::query()->where('slug', $slug)->exists());
    }

    private function uniqueModelSlug(string $name, int $brandId): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'model-'.$brandId;
        }

        return $this->nextAvailableSlug($base, fn (string $slug): bool => ProductModel::query()
            ->where('brand_id', $brandId)
            ->where('slug', $slug)
            ->exists());
    }

    private function nextAvailableSlug(string $base, callable $exists): string
    {
        $slug = $base;
        $suffix = 2;

        while ($exists($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function cleanName(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function normalizedName(string $value): string
    {
        return mb_strtolower($this->cleanName($value));
    }
}
