<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Device;
use Illuminate\Database\Seeder;

class AssignExistingDevicesToBranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Assign all existing devices to Lilongwe branch (default)
        $lilongwe = Branch::where('code', 'LLW')->first();

        if ($lilongwe) {
            Device::whereNull('branch_id')->update([
                'branch_id' => $lilongwe->id,
            ]);
        }
    }
}
