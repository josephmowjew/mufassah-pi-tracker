<?php

use App\Enums\DeviceStatus;
use App\Enums\IotModuleStatus;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\DeviceLocation;
use App\Models\IotModule;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);

    $this->user = User::factory()->create();
    $this->user->assignRole('customer');

    $this->category = DeviceCategory::factory()->create();

    // Create IoT module with a known API token
    $this->plainToken = 'test-api-token-12345';
    $this->module = IotModule::factory()->create([
        'module_id' => 'MOD-TEST-001',
        'api_token' => Hash::make($this->plainToken),
        'status' => IotModuleStatus::Active,
    ]);

    $this->device = Device::factory()
        ->for($this->category, 'category')
        ->for($this->user, 'owner')
        ->create([
            'iot_module_id' => $this->module->id,
            'status' => DeviceStatus::ACTIVE,
        ]);

    // Set the auth header for IoT requests
    $this->iotAuthHeader = "Bearer {$this->module->module_id}:{$this->plainToken}";
});

describe('IoT Authentication', function () {
    it('rejects requests without authorization header', function () {
        $response = $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'Missing or invalid authorization header.');
    });

    it('rejects requests with invalid token format', function () {
        $response = $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
        ], ['Authorization' => 'Bearer invalid-format']);

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid token format. Expected: module_id:api_token');
    });

    it('rejects requests with non-existent module', function () {
        $response = $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
        ], ['Authorization' => 'Bearer NON-EXISTENT:token']);

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'IoT module not found.');
    });

    it('rejects requests with invalid API token', function () {
        $response = $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
        ], ['Authorization' => "Bearer {$this->module->module_id}:wrong-token"]);

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid API token.');
    });

    it('rejects requests from inactive module', function () {
        $this->module->update(['status' => IotModuleStatus::Inactive]);

        $response = $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
        ], ['Authorization' => $this->iotAuthHeader]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'IoT module is not active.');
    });

    it('rejects requests from module not linked to device', function () {
        $this->device->update(['iot_module_id' => null]);

        $response = $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
        ], ['Authorization' => $this->iotAuthHeader]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'IoT module is not linked to any device.');
    });
});

describe('Location Store', function () {
    it('stores location update from IoT module', function () {
        $response = $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
            'altitude' => 1050.5,
            'accuracy' => 5.2,
            'speed' => 12.5,
            'heading' => 180.0,
        ], ['Authorization' => $this->iotAuthHeader]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Location recorded successfully.')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'latitude', 'longitude', 'recorded_at'],
            ]);

        // Verify location was stored
        $this->assertDatabaseHas('device_locations', [
            'device_id' => $this->device->id,
            'iot_module_id' => $this->module->id,
            'is_manual' => false,
        ]);

        // Verify device was updated
        $this->device->refresh();
        expect($this->device->last_location_id)->not->toBeNull();
        expect($this->device->last_seen_at)->not->toBeNull();
    });

    it('updates module battery and signal strength', function () {
        $response = $this->postJson('/api/v1/iot/location', [
            'latitude' => -13.9626,
            'longitude' => 33.7741,
            'battery_level' => 75,
            'signal_strength' => 85,
        ], ['Authorization' => $this->iotAuthHeader]);

        $response->assertCreated();

        $this->module->refresh();
        expect($this->module->battery_level)->toBe(75);
        expect($this->module->signal_strength)->toBe(85);
    });

    it('validates location coordinates', function () {
        $response = $this->postJson('/api/v1/iot/location', [
            'latitude' => 100, // Invalid
            'longitude' => 200, // Invalid
        ], ['Authorization' => $this->iotAuthHeader]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    });
});

describe('Batch Location Store', function () {
    it('stores multiple locations in batch', function () {
        $locations = [
            [
                'latitude' => -13.9626,
                'longitude' => 33.7741,
                'recorded_at' => now()->subMinutes(10)->toIso8601String(),
            ],
            [
                'latitude' => -13.9630,
                'longitude' => 33.7745,
                'recorded_at' => now()->subMinutes(5)->toIso8601String(),
            ],
            [
                'latitude' => -13.9635,
                'longitude' => 33.7750,
                'recorded_at' => now()->toIso8601String(),
            ],
        ];

        $response = $this->postJson('/api/v1/iot/locations/batch', [
            'locations' => $locations,
        ], ['Authorization' => $this->iotAuthHeader]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.count', 3);

        // Verify all locations were stored
        expect(DeviceLocation::where('device_id', $this->device->id)->count())->toBe(3);
    });

    it('limits batch size to 100 locations', function () {
        $locations = collect(range(1, 101))->map(fn ($i) => [
            'latitude' => -13.9626 + ($i * 0.0001),
            'longitude' => 33.7741 + ($i * 0.0001),
            'recorded_at' => now()->subMinutes($i)->toIso8601String(),
        ])->toArray();

        $response = $this->postJson('/api/v1/iot/locations/batch', [
            'locations' => $locations,
        ], ['Authorization' => $this->iotAuthHeader]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['locations']);
    });
});

describe('Heartbeat', function () {
    it('processes heartbeat and returns configuration', function () {
        $response = $this->postJson('/api/v1/iot/heartbeat', [
            'battery_level' => 80,
            'signal_strength' => 90,
            'firmware_version' => '1.2.3',
        ], ['Authorization' => $this->iotAuthHeader]);

        $response->assertSuccessful()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Heartbeat received.')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['server_time', 'tracking_interval', 'config'],
            ]);

        // Verify module was updated
        $this->module->refresh();
        expect($this->module->battery_level)->toBe(80);
        expect($this->module->signal_strength)->toBe(90);
        expect($this->module->firmware_version)->toBe('1.2.3');
        expect($this->module->last_ping)->not->toBeNull();
    });

    it('returns shorter tracking interval for stolen devices', function () {
        $this->device->update(['status' => DeviceStatus::STOLEN]);

        $response = $this->postJson('/api/v1/iot/heartbeat', [], [
            'Authorization' => $this->iotAuthHeader,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.tracking_interval', 30);
    });

    it('returns normal tracking interval for active devices', function () {
        $response = $this->postJson('/api/v1/iot/heartbeat', [], [
            'Authorization' => $this->iotAuthHeader,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.tracking_interval', 300);
    });
});
