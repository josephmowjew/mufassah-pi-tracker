<?php

use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);
});

describe('Device Category Listing', function () {
    it('returns active categories for authenticated customer', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        DeviceCategory::factory()->count(3)->create(['is_active' => true]);
        DeviceCategory::factory()->create(['is_active' => false]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl('device-categories'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'slug', 'description', 'icon', 'is_active'],
                ],
            ]);
    });

    it('returns all categories including inactive for admin', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        DeviceCategory::factory()->count(3)->create(['is_active' => true]);
        DeviceCategory::factory()->count(2)->create(['is_active' => false]);

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('device-categories'))
            ->assertOk()
            ->assertJsonCount(5, 'data');
    });

    it('includes device count when requested', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create(['is_active' => true]);
        Device::factory()->count(3)->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl('device-categories?with_count=1'))
            ->assertOk()
            ->assertJsonPath('data.0.devices_count', 3);
    });

    it('requires authentication', function () {
        getJson(apiUrl('device-categories'))
            ->assertUnauthorized();
    });
});

describe('Device Category View', function () {
    it('returns category details', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create(['is_active' => true]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("device-categories/{$category->id}"))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ],
            ]);
    });
});

describe('Device Category Creation (Admin)', function () {
    it('allows admin to create a category', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/device-categories'), [
                'name' => 'Smartphones',
                'description' => 'Mobile phones and smartphones',
                'icon' => 'smartphone',
            ])
            ->assertCreated()
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Smartphones',
                    'slug' => 'smartphones',
                ],
            ]);

        $this->assertDatabaseHas('device_categories', [
            'name' => 'Smartphones',
            'slug' => 'smartphones',
        ]);
    });

    it('auto-generates slug from name', function () {
        $admin = User::factory()->create();
        $admin->assignRole('store_admin');

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/device-categories'), [
                'name' => 'Gaming Consoles',
                'description' => 'Video game consoles',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'gaming-consoles');
    });

    it('allows custom slug', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/device-categories'), [
                'name' => 'Smartphones',
                'slug' => 'phones',
                'description' => 'All phones',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'phones');
    });

    it('validates unique slug', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        DeviceCategory::factory()->create(['slug' => 'existing-slug']);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/device-categories'), [
                'name' => 'New Category',
                'slug' => 'existing-slug',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    });

    it('prevents non-admin from creating categories', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('admin/device-categories'), [
                'name' => 'Test Category',
            ])
            ->assertForbidden();
    });

    it('validates required name field', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/device-categories'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });
});

describe('Device Category Update (Admin)', function () {
    it('allows admin to update a category', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $category = DeviceCategory::factory()->create();

        actingAs($admin, 'sanctum')
            ->putJson(apiUrl("admin/device-categories/{$category->id}"), [
                'name' => 'Updated Name',
                'description' => 'Updated description',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Name',
                    'description' => 'Updated description',
                ],
            ]);
    });

    it('allows toggling category active status', function () {
        $admin = User::factory()->create();
        $admin->assignRole('store_admin');
        $category = DeviceCategory::factory()->create(['is_active' => true]);

        actingAs($admin, 'sanctum')
            ->putJson(apiUrl("admin/device-categories/{$category->id}"), [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    it('prevents non-admin from updating categories', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        actingAs($user, 'sanctum')
            ->putJson(apiUrl("admin/device-categories/{$category->id}"), [
                'name' => 'Hacked Name',
            ])
            ->assertForbidden();
    });
});

describe('Device Category Deletion (Admin)', function () {
    it('allows super_admin to delete empty category', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $category = DeviceCategory::factory()->create();

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("admin/device-categories/{$category->id}"))
            ->assertOk();

        $this->assertDatabaseMissing('device_categories', ['id' => $category->id]);
    });

    it('prevents deletion of category with devices', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $user = User::factory()->create();
        $category = DeviceCategory::factory()->create();
        Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("admin/device-categories/{$category->id}"))
            ->assertBadRequest()
            ->assertJson([
                'success' => false,
            ]);
    });

    it('prevents store_admin from deleting categories', function () {
        $admin = User::factory()->create();
        $admin->assignRole('store_admin');
        $category = DeviceCategory::factory()->create();

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("admin/device-categories/{$category->id}"))
            ->assertForbidden();
    });

    it('prevents customer from deleting categories', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("admin/device-categories/{$category->id}"))
            ->assertForbidden();
    });
});
