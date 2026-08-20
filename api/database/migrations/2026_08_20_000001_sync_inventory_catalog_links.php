<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('devices')
            ->select(['id', 'category_id', 'brand', 'model', 'brand_id', 'product_model_id'])
            ->orderBy('id')
            ->chunkById(200, function ($devices): void {
                foreach ($devices as $device) {
                    $brandName = $this->cleanName($device->brand);
                    $modelName = $this->cleanName($device->model);

                    if ($brandName === '' || $modelName === '') {
                        continue;
                    }

                    $category = DB::table('device_categories')
                        ->where('id', $device->category_id)
                        ->first(['id', 'slug']);

                    if (! $category) {
                        continue;
                    }

                    $brand = $this->findBrand($category->id, $brandName);
                    if (! $brand) {
                        $brandId = DB::table('brands')->insertGetId([
                            'category_id' => $category->id,
                            'name' => $brandName,
                            'slug' => $this->uniqueBrandSlug($brandName, $category),
                            'description' => 'Automatically added from inventory intake.',
                            'is_active' => true,
                            'sort_order' => ((int) DB::table('brands')->where('category_id', $category->id)->max('sort_order')) + 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $brand = (object) ['id' => $brandId, 'name' => $brandName];
                    }

                    $model = $this->findModel($brand->id, $modelName);
                    if (! $model) {
                        $modelId = DB::table('product_models')->insertGetId([
                            'brand_id' => $brand->id,
                            'name' => $modelName,
                            'slug' => $this->uniqueModelSlug($modelName, $brand->id),
                            'description' => 'Automatically added from inventory intake.',
                            'is_active' => true,
                            'sort_order' => ((int) DB::table('product_models')->where('brand_id', $brand->id)->max('sort_order')) + 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $model = (object) ['id' => $modelId, 'name' => $modelName];
                    }

                    $updates = [];
                    if ((int) $device->brand_id !== (int) $brand->id) {
                        $updates['brand_id'] = $brand->id;
                    }
                    if ((int) $device->product_model_id !== (int) $model->id) {
                        $updates['product_model_id'] = $model->id;
                    }
                    if ($device->brand !== $brand->name) {
                        $updates['brand'] = $brand->name;
                    }
                    if ($device->model !== $model->name) {
                        $updates['model'] = $model->name;
                    }

                    if ($updates !== []) {
                        DB::table('devices')->where('id', $device->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Catalog links and automatically created entries are additive data.
        // They must not be removed during a migration rollback.
    }

    private function findBrand(int $categoryId, string $name): ?object
    {
        return DB::table('brands')
            ->where('category_id', $categoryId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$this->normalizedName($name)])
            ->first(['id', 'name']);
    }

    private function findModel(int $brandId, string $name): ?object
    {
        return DB::table('product_models')
            ->where('brand_id', $brandId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$this->normalizedName($name)])
            ->first(['id', 'name']);
    }

    private function uniqueBrandSlug(string $name, object $category): string
    {
        $base = Str::slug($name.'-'.($category->slug ?: 'category-'.$category->id));
        if ($base === '') {
            $base = 'brand-'.$category->id;
        }

        return $this->nextAvailableSlug($base, fn (string $slug): bool => DB::table('brands')->where('slug', $slug)->exists());
    }

    private function uniqueModelSlug(string $name, int $brandId): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'model-'.$brandId;
        }

        return $this->nextAvailableSlug($base, fn (string $slug): bool => DB::table('product_models')
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
};
