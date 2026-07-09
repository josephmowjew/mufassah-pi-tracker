<?php

namespace Database\Seeders;

use App\Models\DeviceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DeviceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Television', 'icon' => 'tv', 'description' => 'TVs and display screens'],
            ['name' => 'Smartphone', 'icon' => 'smartphone', 'description' => 'Mobile phones and smartphones'],
            ['name' => 'Laptop', 'icon' => 'laptop', 'description' => 'Laptop computers'],
            ['name' => 'Desktop Computer', 'icon' => 'desktop', 'description' => 'Desktop computers and workstations'],
            ['name' => 'Tablet', 'icon' => 'tablet', 'description' => 'Tablet devices'],
            ['name' => 'Home Appliance', 'icon' => 'home', 'description' => 'Refrigerators, washing machines, etc.'],
            ['name' => 'Audio Equipment', 'icon' => 'speaker', 'description' => 'Speakers, soundbars, and audio systems'],
            ['name' => 'Camera', 'icon' => 'camera', 'description' => 'Digital cameras and camcorders'],
            ['name' => 'Wearable Device', 'icon' => 'watch', 'description' => 'Smartwatches and fitness trackers'],
            ['name' => 'Gaming Console', 'icon' => 'gamepad', 'description' => 'Video game consoles'],
            ['name' => 'Printer', 'icon' => 'printer', 'description' => 'Printers and scanners'],
            ['name' => 'Network Equipment', 'icon' => 'wifi', 'description' => 'Routers, switches, and modems'],
            ['name' => 'Other Electronics', 'icon' => 'cpu', 'description' => 'Other electronic devices'],
        ];

        foreach ($categories as $category) {
            DeviceCategory::updateOrCreate(
                ['slug' => Str::slug($category['name'])],
                [
                    'name' => $category['name'],
                    'slug' => Str::slug($category['name']),
                    'icon' => $category['icon'],
                    'description' => $category['description'],
                    'is_active' => true,
                ]
            );
        }
    }
}
