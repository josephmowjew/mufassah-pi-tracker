<?php

use App\Enums\DeviceStatus;
use App\Enums\IotModuleStatus;
use App\Enums\TheftReportStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\DeviceLocation;
use App\Models\DeviceOwnershipHistory;
use App\Models\IotModule;
use App\Models\TheftReport;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\actingAs;

/*
 * Regression cover for E-06: multi-step writes must be atomic.
 *
 * Each test forces a failure at the *last* write of a sequence and asserts the
 * earlier writes were rolled back with it. The choice of failure point is what
 * makes these meaningful — it has to be a write the transaction actually covers.
 *
 * Two traps worth recording:
 *   - The IoT auth middleware updates the module (last_ping) on every request,
 *     so a module-level failure would abort before the controller and the test
 *     would pass without proving anything. The device update is the safe point.
 *   - A bare `count() === 0` is not proof on its own; each test also asserts the
 *     specific artefact the first write would have left behind.
 */

beforeEach(function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->category = DeviceCategory::factory()->create(['name' => 'Smartphones']);
    $this->branch = Branch::create([
        'name' => 'Test Branch',
        'code' => 'TST',
        'is_active' => true,
    ]);
});

describe('Theft report deletion', function () {
    it('keeps the device flagged stolen when the report cannot be deleted', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $owner = User::factory()->create();
        $owner->assignRole('customer');

        $device = Device::factory()
            ->for($owner, 'owner')
            ->for($this->category, 'category')
            ->create(['status' => DeviceStatus::STOLEN]);

        $report = TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $owner->id,
            'status' => TheftReportStatus::Reported,
        ]);

        // The status reset runs first. Without a transaction it survives the
        // failed delete, leaving an ACTIVE device with an open theft report.
        TheftReport::deleting(function () {
            throw new RuntimeException('simulated delete failure');
        });

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("theft-reports/{$report->id}"))
            ->assertStatus(500);

        expect($device->fresh()->status)->toBe(DeviceStatus::STOLEN)
            ->and(TheftReport::find($report->id))->not->toBeNull();
    });
});

describe('Device registration', function () {
    it('does not leave an orphan device when the ownership record fails', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        DeviceOwnershipHistory::creating(function () {
            throw new RuntimeException('simulated ownership write failure');
        });

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices'), [
                'serial_number' => 'SN-ATOMIC-001',
                'category_id' => $this->category->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15',
            ])
            ->assertStatus(500);

        expect(Device::where('serial_number', 'SN-ATOMIC-001')->exists())->toBeFalse();
    });
});

describe('Inventory device add', function () {
    it('does not leave a device without its opening movement', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->mock(\App\Services\InventoryMovementService::class, function ($mock) {
            $mock->shouldReceive('recordDeviceMovement')
                ->andThrow(new RuntimeException('simulated ledger failure'));
        });

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/inventory'), [
                'serial_number' => 'SN-LEDGER-001',
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15 Pro',
            ])
            ->assertStatus(500);

        expect(Device::where('serial_number', 'SN-LEDGER-001')->exists())->toBeFalse();
    });
});

describe('IoT location ingestion', function () {
    beforeEach(function () {
        $owner = User::factory()->create();
        $owner->assignRole('customer');

        $this->plainToken = 'atomic-test-token';
        $this->module = IotModule::factory()->create([
            'module_id' => 'MOD-ATOMIC-001',
            'api_token' => Hash::make($this->plainToken),
            'status' => IotModuleStatus::Active,
        ]);

        $this->device = Device::factory()
            ->for($owner, 'owner')
            ->for($this->category, 'category')
            ->create([
                'iot_module_id' => $this->module->id,
                'status' => DeviceStatus::ACTIVE,
            ]);

        $this->iotAuthHeader = "Bearer {$this->module->module_id}:{$this->plainToken}";
    });

    it('rolls the single location back when the device pointer cannot be updated', function () {
        Device::updating(function () {
            throw new RuntimeException('simulated device update failure');
        });

        $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
        ], ['Authorization' => $this->iotAuthHeader])
            ->assertStatus(500);

        // The location row is inserted before the device update, so a surviving
        // row here would mean the rollback did not happen.
        expect(DeviceLocation::where('device_id', $this->device->id)->count())->toBe(0)
            ->and($this->device->fresh()->last_location_id)->toBeNull();
    });

    it('rolls every location of a batch back when the device pointer cannot be updated', function () {
        Device::updating(function () {
            throw new RuntimeException('simulated device update failure');
        });

        $this->postJson('/api/v1/iot/locations/batch', [
            'locations' => [
                ['latitude' => -13.9626, 'longitude' => 33.7741, 'recorded_at' => now()->subMinutes(2)->toIso8601String()],
                ['latitude' => -13.9630, 'longitude' => 33.7745, 'recorded_at' => now()->subMinute()->toIso8601String()],
                ['latitude' => -13.9635, 'longitude' => 33.7750, 'recorded_at' => now()->toIso8601String()],
            ],
        ], ['Authorization' => $this->iotAuthHeader])
            ->assertStatus(500);

        expect(DeviceLocation::where('device_id', $this->device->id)->count())->toBe(0)
            ->and($this->device->fresh()->last_location_id)->toBeNull();
    });

    it('still records a location when nothing goes wrong', function () {
        $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
        ], ['Authorization' => $this->iotAuthHeader])
            ->assertCreated();

        expect(DeviceLocation::where('device_id', $this->device->id)->count())->toBe(1)
            ->and($this->device->fresh()->last_location_id)->not->toBeNull();
    });
});
