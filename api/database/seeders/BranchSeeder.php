<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Lilongwe branch (default branch for existing data)
        Branch::create([
            'name' => 'Lilongwe Branch',
            'code' => 'LLW',
            'address' => 'Area 14, Lilongwe',
            'city' => 'Lilongwe',
            'phone' => '+265 1 xxx xxxx',
            'email' => 'lilongwe@mufassah.mw',
            'is_active' => true,
        ]);

        // Create Blantyre branch
        Branch::create([
            'name' => 'Blantyre Branch',
            'code' => 'BLA',
            'address' => 'Ginnery Corner, Blantyre',
            'city' => 'Blantyre',
            'phone' => '+265 1 8xx xxxx',
            'email' => 'blantyre@mufassah.mw',
            'is_active' => true,
        ]);
    }
}
