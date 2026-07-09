<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles and permissions first
        $this->call([
            RolesAndPermissionsSeeder::class,
            DeviceCategorySeeder::class,
            SubscriptionPlanSeeder::class,
        ]);

        // Create Super Admin user
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@mufasah.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'phone_number' => '+265888000001',
        ]);
        $superAdmin->assignRole(\Spatie\Permission\Models\Role::findByName('super_admin', 'api'));

        // Create Store Admin user
        $storeAdmin = User::create([
            'name' => 'Store Admin',
            'email' => 'store@mufasah.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'phone_number' => '+265888000002',
        ]);
        $storeAdmin->assignRole(\Spatie\Permission\Models\Role::findByName('store_admin', 'api'));

        // Create Customer Service user
        $customerService = User::create([
            'name' => 'Customer Service',
            'email' => 'support@mufasah.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'phone_number' => '+265888000003',
        ]);
        $customerService->assignRole(\Spatie\Permission\Models\Role::findByName('customer_service', 'api'));

        // Create Test Customer user
        $customer = User::create([
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'phone_number' => '+265888000004',
            'address' => 'Area 47, Lilongwe',
            'city' => 'Lilongwe',
        ]);
        $customer->assignRole(\Spatie\Permission\Models\Role::findByName('customer', 'api'));
    }
}
