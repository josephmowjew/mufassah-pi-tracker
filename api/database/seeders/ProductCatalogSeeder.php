<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\DeviceCategory;
use App\Models\ProductModel;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductCatalogSeeder extends Seeder
{
    /**
     * Base prices for different categories (in MWK).
     */
    private array $categoryBasePrices = [
        'smartphone' => [150000, 2500000],
        'laptop' => [350000, 4500000],
        'tablet' => [180000, 2000000],
        'television' => [150000, 3500000],
        'wearable-device' => [50000, 850000],
        'audio-equipment' => [25000, 750000],
        'gaming-console' => [450000, 1200000],
        'camera' => [200000, 3500000],
        'desktop-computer' => [250000, 5000000],
        'printer' => [75000, 650000],
        'network-equipment' => [35000, 450000],
        'home-appliance' => [80000, 1500000],
        'other-electronics' => [15000, 350000],
    ];

    /**
     * Available colors for different categories.
     */
    private array $categoryColors = [
        'smartphone' => ['Black', 'White', 'Silver', 'Gold', 'Blue', 'Red', 'Green', 'Space Gray', 'Midnight', 'Starlight', 'Purple', 'Titanium Blue', 'Titanium Natural'],
        'laptop' => ['Silver', 'Space Gray', 'Midnight', 'Starlight', 'Black', 'White', 'Gold'],
        'tablet' => ['Space Gray', 'Silver', 'Pink', 'Blue', 'Purple', 'Starlight', 'Midnight'],
        'television' => ['Black', 'White', 'Silver'],
        'wearable-device' => ['Midnight', 'Starlight', 'Silver', 'Graphite', 'Gold', 'Blue', 'Green', 'Orange', 'Black'],
        'audio-equipment' => ['Black', 'White', 'Silver', 'Space Gray', 'Blue', 'Red', 'Green'],
        'gaming-console' => ['Black', 'White', 'Digital Edition'],
        'camera' => ['Black', 'Silver', 'Black/Silver'],
        'desktop-computer' => ['Silver', 'Space Gray', 'Black'],
        'printer' => ['Black', 'White', 'Gray'],
        'network-equipment' => ['Black', 'White'],
        'home-appliance' => ['Black', 'White', 'Silver', 'Stainless Steel', 'Bespoke Navy', 'Bespoke Pink'],
        'other-electronics' => ['Black', 'White', 'Silver', 'Space Gray'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding product catalog...');

        // Get the device data from DeviceFactory
        $devicesByCategory = DeviceFactory::$devicesByCategory;

        // Get all categories
        $categories = DeviceCategory::all()->keyBy('slug');

        if ($categories->isEmpty()) {
            $this->command->error('No device categories found. Please run the DeviceCategorySeeder first.');

            return;
        }

        $totalBrands = 0;
        $totalModels = 0;

        foreach ($devicesByCategory as $categorySlug => $brands) {
            $category = $categories->get($categorySlug);

            if (! $category) {
                $this->command->warn("Category '{$categorySlug}' not found, skipping...");

                continue;
            }

            $this->command->info("  Processing category: {$category->name}");

            $sortOrder = 0;
            foreach ($brands as $brandName => $models) {
                // Generate unique slug including category for global uniqueness
                $brandSlug = Str::slug($brandName.'-'.$categorySlug);

                // Create or find the brand
                $brand = Brand::firstOrCreate(
                    [
                        'category_id' => $category->id,
                        'name' => $brandName,
                    ],
                    [
                        'slug' => $brandSlug,
                        'description' => "Official {$brandName} products for {$category->name}",
                        'is_active' => true,
                        'sort_order' => $sortOrder++,
                    ]
                );

                if ($brand->wasRecentlyCreated) {
                    $totalBrands++;
                }

                // Create product models for this brand
                $modelSortOrder = 0;
                foreach ($models as $modelName) {
                    $priceRange = $this->categoryBasePrices[$categorySlug] ?? [50000, 500000];
                    $colors = $this->categoryColors[$categorySlug] ?? ['Black', 'White', 'Silver'];

                    // Generate a reasonable price based on model name (flagship models cost more)
                    $basePrice = $this->generatePrice($modelName, $priceRange);

                    // Select 2-4 random colors
                    $availableColors = collect($colors)
                        ->shuffle()
                        ->take(fake()->numberBetween(2, min(4, count($colors))))
                        ->values()
                        ->toArray();

                    // Generate unique slug for this brand
                    $baseSlug = Str::slug($modelName);
                    $slug = $this->getUniqueSlug($brand->id, $baseSlug);

                    $productModel = ProductModel::firstOrCreate(
                        [
                            'brand_id' => $brand->id,
                            'name' => $modelName,
                        ],
                        [
                            'slug' => $slug,
                            'base_price' => $basePrice,
                            'available_colors' => $availableColors,
                            'is_active' => true,
                            'sort_order' => $modelSortOrder++,
                        ]
                    );

                    if ($productModel->wasRecentlyCreated) {
                        $totalModels++;
                    }
                }
            }
        }

        $this->command->info("Product catalog seeded: {$totalBrands} brands, {$totalModels} models created.");
    }

    /**
     * Generate a realistic price based on model name.
     */
    private function generatePrice(string $modelName, array $priceRange): float
    {
        [$minPrice, $maxPrice] = $priceRange;
        $midPrice = ($minPrice + $maxPrice) / 2;

        // Flagship indicators (higher price)
        $flagshipKeywords = ['Pro', 'Ultra', 'Max', 'Plus', '+', 'Premium', 'Elite', 'Master', 'X1', 'Series X', 'Fold', 'Studio'];
        $budgetKeywords = ['SE', 'Lite', 'Mini', 'Go', 'A', 'E', 'Inspire', 'Basic', 'Smart'];

        foreach ($flagshipKeywords as $keyword) {
            if (stripos($modelName, $keyword) !== false) {
                // Higher end of price range
                return fake()->randomFloat(2, $midPrice, $maxPrice);
            }
        }

        foreach ($budgetKeywords as $keyword) {
            if (stripos($modelName, $keyword) !== false) {
                // Lower end of price range
                return fake()->randomFloat(2, $minPrice, $midPrice);
            }
        }

        // Mid-range
        return fake()->randomFloat(2, $minPrice * 1.2, $maxPrice * 0.8);
    }

    /**
     * Get a unique slug for a product model within a brand.
     */
    private function getUniqueSlug(int $brandId, string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 1;

        while (ProductModel::where('brand_id', $brandId)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.++$counter;
        }

        return $slug;
    }
}
