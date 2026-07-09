<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // User management
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',
            'manage-user-roles',

            // Device management
            'view-devices',
            'create-devices',
            'edit-devices',
            'delete-devices',
            'view-all-devices',
            'manage-device-status',

            // IoT Module management
            'view-iot-modules',
            'create-iot-modules',
            'edit-iot-modules',
            'delete-iot-modules',
            'assign-iot-modules',

            // Location tracking
            'view-own-device-locations',
            'view-all-device-locations',

            // Geofence management
            'view-geofences',
            'create-geofences',
            'edit-geofences',
            'delete-geofences',

            // Alert management
            'view-own-alerts',
            'view-all-alerts',
            'manage-alerts',

            // Theft reports
            'create-theft-reports',
            'view-own-theft-reports',
            'view-all-theft-reports',
            'manage-theft-reports',
            'investigate-theft-reports',

            // Warranty management
            'view-warranties',
            'create-warranty-claims',
            'view-own-warranty-claims',
            'view-all-warranty-claims',
            'manage-warranty-claims',

            // Subscription management
            'view-subscription-plans',
            'manage-subscription-plans',
            'view-own-subscriptions',
            'view-all-subscriptions',
            'manage-subscriptions',

            // Payment management
            'view-own-payments',
            'view-all-payments',
            'manage-payments',
            'process-refunds',

            // Sales & inventory management
            'view-sales',
            'manage-sales',
            'view-inventory',
            'manage-inventory',
            'view-stock-movements',
            'manage-stock-movements',
            'request-stock-transfers',
            'approve-stock-transfers',

            // System administration
            'view-dashboard',
            'view-admin-dashboard',
            'manage-system-settings',
            'view-activity-logs',
            'manage-backups',
            'generate-reports',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // Create roles and assign permissions
        // Super Admin - Full system access
        $superAdmin = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'api',
        ]);
        $superAdmin->syncPermissions(Permission::all());

        // Store Admin - Store management and reports
        $storeAdmin = Role::firstOrCreate([
            'name' => 'store_admin',
            'guard_name' => 'api',
        ]);
        $storeAdmin->syncPermissions([
            'view-users',
            'create-users',
            'edit-users',
            'view-devices',
            'create-devices',
            'edit-devices',
            'view-all-devices',
            'manage-device-status',
            'view-iot-modules',
            'create-iot-modules',
            'edit-iot-modules',
            'assign-iot-modules',
            'view-all-device-locations',
            'view-all-alerts',
            'manage-alerts',
            'view-all-theft-reports',
            'manage-theft-reports',
            'investigate-theft-reports',
            'view-warranties',
            'view-all-warranty-claims',
            'manage-warranty-claims',
            'view-subscription-plans',
            'view-all-subscriptions',
            'manage-subscriptions',
            'view-all-payments',
            'view-sales',
            'manage-sales',
            'view-inventory',
            'manage-inventory',
            'view-stock-movements',
            'manage-stock-movements',
            'request-stock-transfers',
            'approve-stock-transfers',
            'view-dashboard',
            'view-admin-dashboard',
            'view-activity-logs',
            'generate-reports',
        ]);

        // Customer Service - Customer support and device management
        $customerService = Role::firstOrCreate([
            'name' => 'customer_service',
            'guard_name' => 'api',
        ]);
        $customerService->syncPermissions([
            'view-users',
            'view-devices',
            'view-all-devices',
            'view-iot-modules',
            'view-all-alerts',
            'view-all-theft-reports',
            'investigate-theft-reports',
            'view-warranties',
            'view-all-warranty-claims',
            'view-subscription-plans',
            'view-all-subscriptions',
            'view-all-payments',
            'view-sales',
            'view-dashboard',
        ]);

        // Customer - Device owner with tracking capabilities
        $customer = Role::firstOrCreate([
            'name' => 'customer',
            'guard_name' => 'api',
        ]);
        $customer->syncPermissions([
            'view-devices',
            'create-devices',
            'edit-devices',
            'delete-devices',
            'view-own-device-locations',
            'view-geofences',
            'create-geofences',
            'edit-geofences',
            'delete-geofences',
            'view-own-alerts',
            'create-theft-reports',
            'view-own-theft-reports',
            'view-warranties',
            'create-warranty-claims',
            'view-own-warranty-claims',
            'view-subscription-plans',
            'view-own-subscriptions',
            'view-own-payments',
            'view-dashboard',
        ]);
    }
}
