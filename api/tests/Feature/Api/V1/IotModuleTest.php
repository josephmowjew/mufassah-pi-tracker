<?php

use App\Enums\IotModuleStatus;
use App\Enums\ModemType;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\IotModule;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);
});

describe('IoT Module Listing (Admin)', function () {
    it('allows admin to list all IoT modules', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        IotModule::factory()->count(5)->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('admin/iot-modules'))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'module_id', 'imei', 'sim_number', 'modem_type', 'status'],
                ],
                'meta',
            ]);
    });

    it('filters modules by status', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        IotModule::factory()->count(3)->create(['status' => IotModuleStatus::Active]);
        IotModule::factory()->count(2)->create(['status' => IotModuleStatus::Inactive]);

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('admin/iot-modules?status=active'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters modules by modem type', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        IotModule::factory()->count(3)->create(['modem_type' => ModemType::Gps]);
        IotModule::factory()->count(2)->create(['modem_type' => ModemType::Gsm]);

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('admin/iot-modules?modem_type=GPS'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('searches modules by module_id, sim_number, or imei', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        IotModule::factory()->create(['module_id' => 'MOD-ABC123']);
        IotModule::factory()->create(['module_id' => 'MOD-DEF456']);

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('admin/iot-modules?search=ABC123'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('prevents non-admin from listing modules', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        actingAs($user, 'sanctum')
            ->getJson(apiUrl('admin/iot-modules'))
            ->assertForbidden();
    });
});

describe('IoT Module View (Admin)', function () {
    it('allows admin to view module details', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $module = IotModule::factory()->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl("admin/iot-modules/{$module->id}"))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'module_id',
                    'imei',
                    'sim_number',
                    'modem_type',
                    'status',
                    'firmware_version',
                    'configuration',
                ],
            ]);
    });

});

describe('IoT Module Creation (Admin)', function () {
    it('allows admin to register a new IoT module', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $response = actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/iot-modules'), [
                'module_id' => 'MOD-123456',
                'imei' => '123456789012345',
                'sim_number' => '+265999123456',
                'modem_type' => 'GPS',
                'firmware_version' => '1.0.0',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'module',
                    'api_token',
                ],
            ]);

        $this->assertDatabaseHas('iot_modules', [
            'module_id' => 'MOD-123456',
            'imei' => '123456789012345',
            'status' => IotModuleStatus::Inactive->value,
        ]);
    });

    it('returns API token only during creation', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $response = actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/iot-modules'), [
                'module_id' => 'MOD-TEST',
                'imei' => '987654321012345',
                'sim_number' => '+265888123456',
                'modem_type' => 'GSM',
            ]);

        $response->assertCreated();

        $apiToken = $response->json('data.api_token');
        expect($apiToken)->not->toBeNull()->toHaveLength(64);
    });

    it('validates required fields', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/iot-modules'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['module_id', 'imei', 'sim_number', 'modem_type']);
    });

    it('validates unique module_id', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        IotModule::factory()->create(['module_id' => 'MOD-EXISTING']);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/iot-modules'), [
                'module_id' => 'MOD-EXISTING',
                'imei' => '123456789012345',
                'sim_number' => '+265999123456',
                'modem_type' => 'GPS',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['module_id']);
    });

    it('validates unique imei', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        IotModule::factory()->create(['imei' => '123456789012345']);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/iot-modules'), [
                'module_id' => 'MOD-NEW',
                'imei' => '123456789012345',
                'sim_number' => '+265999123456',
                'modem_type' => 'GPS',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['imei']);
    });

    it('validates imei format (15 digits)', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/iot-modules'), [
                'module_id' => 'MOD-NEW',
                'imei' => '12345', // Too short
                'sim_number' => '+265999123456',
                'modem_type' => 'GPS',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['imei']);
    });

    it('prevents non-admin from creating modules', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('admin/iot-modules'), [
                'module_id' => 'MOD-TEST',
                'imei' => '123456789012345',
                'sim_number' => '+265999123456',
                'modem_type' => 'GPS',
            ])
            ->assertForbidden();
    });
});

describe('IoT Module Update (Admin)', function () {
    it('allows admin to update module', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $module = IotModule::factory()->create();

        actingAs($admin, 'sanctum')
            ->putJson(apiUrl("admin/iot-modules/{$module->id}"), [
                'firmware_version' => '2.0.0',
                'sim_number' => '+265888999888',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'firmware_version' => '2.0.0',
                ],
            ]);
    });

    it('allows updating module configuration', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $module = IotModule::factory()->create();

        actingAs($admin, 'sanctum')
            ->putJson(apiUrl("admin/iot-modules/{$module->id}"), [
                'configuration' => [
                    'update_interval' => 60,
                    'gps_accuracy' => 'high',
                ],
            ])
            ->assertOk();

        $module->refresh();
        expect($module->configuration)->toHaveKey('update_interval', 60);
    });

    it('prevents non-admin from updating modules', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $module = IotModule::factory()->create();

        actingAs($user, 'sanctum')
            ->putJson(apiUrl("admin/iot-modules/{$module->id}"), [
                'firmware_version' => '3.0.0',
            ])
            ->assertForbidden();
    });
});

describe('IoT Module Deletion (Admin)', function () {
    it('allows super_admin to delete unlinked module', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $module = IotModule::factory()->create();

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("admin/iot-modules/{$module->id}"))
            ->assertOk();

        $this->assertSoftDeleted('iot_modules', ['id' => $module->id]);
    });

    it('prevents deletion of module linked to device', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $user = User::factory()->create();
        $category = DeviceCategory::factory()->create();
        $module = IotModule::factory()->create();
        Device::factory()
            ->for($user, 'owner')
            ->for($category, 'category')
            ->for($module, 'iotModule')
            ->create();

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("admin/iot-modules/{$module->id}"))
            ->assertBadRequest();
    });

    it('prevents store_admin from deleting modules', function () {
        $admin = User::factory()->create();
        $admin->assignRole('store_admin');
        $module = IotModule::factory()->create();

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("admin/iot-modules/{$module->id}"))
            ->assertForbidden();
    });
});

describe('IoT Module Token Regeneration (Admin)', function () {
    it('allows admin to regenerate module API token', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $module = IotModule::factory()->create();
        $oldToken = $module->api_token;

        $response = actingAs($admin, 'sanctum')
            ->postJson(apiUrl("admin/iot-modules/{$module->id}/regenerate-token"));

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['api_token'],
            ]);

        $newToken = $response->json('data.api_token');
        expect($newToken)->not->toBe($oldToken)->toHaveLength(64);
    });
});

describe('IoT Module Activation (Admin)', function () {
    it('allows admin to activate a module', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $module = IotModule::factory()->create(['status' => IotModuleStatus::Inactive]);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("admin/iot-modules/{$module->id}/activate"))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'active');

        $module->refresh();
        expect($module->status)->toBe(IotModuleStatus::Active)
            ->and($module->activated_at)->not->toBeNull();
    });

    it('preserves original activation date on reactivation', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $originalDate = now()->subMonth();
        $module = IotModule::factory()->create([
            'status' => IotModuleStatus::Inactive,
            'activated_at' => $originalDate,
        ]);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("admin/iot-modules/{$module->id}/activate"))
            ->assertOk();

        $module->refresh();
        expect($module->activated_at->format('Y-m-d'))->toBe($originalDate->format('Y-m-d'));
    });

    it('allows admin to deactivate a module', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $module = IotModule::factory()->create(['status' => IotModuleStatus::Active]);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("admin/iot-modules/{$module->id}/deactivate"))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'inactive');

        $module->refresh();
        expect($module->status)->toBe(IotModuleStatus::Inactive);
    });
});

it('limits store admins to IoT modules linked to their branch', function () {
    $branch = Branch::create(['name' => 'IoT Branch', 'code' => 'IOT', 'is_active' => true]);
    $otherBranch = Branch::create(['name' => 'Other IoT Branch', 'code' => 'OIT', 'is_active' => true]);
    $admin = User::factory()->create(['branch_id' => $branch->id]);
    $admin->assignRole('store_admin');
    $ownModule = IotModule::factory()->create();
    $otherModule = IotModule::factory()->create();
    Device::factory()->create(['branch_id' => $branch->id, 'iot_module_id' => $ownModule->id]);
    Device::factory()->create(['branch_id' => $otherBranch->id, 'iot_module_id' => $otherModule->id]);

    actingAs($admin, 'sanctum')
        ->getJson(apiUrl('admin/iot-modules'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownModule->id);

    actingAs($admin, 'sanctum')
        ->getJson(apiUrl("admin/iot-modules/{$otherModule->id}"))
        ->assertForbidden();
});
