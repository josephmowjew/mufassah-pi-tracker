<?php

use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\DeviceLocation;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
});

describe('Device Location History', function () {
    it('returns location history for owned device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->count(5)->for($device)->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations"))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'latitude', 'longitude', 'recorded_at', 'is_manual'],
                ],
            ]);
    });

    it('returns locations ordered by recorded_at descending', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->create(['recorded_at' => now()->subHours(2)]);
        DeviceLocation::factory()->for($device)->create(['recorded_at' => now()]);
        DeviceLocation::factory()->for($device)->create(['recorded_at' => now()->subHour()]);

        $response = actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations"))
            ->assertOk();

        $dates = collect($response->json('data'))->pluck('recorded_at')->toArray();
        expect($dates)->toEqual(collect($dates)->sortDesc()->values()->toArray());
    });

    it('filters locations by date range', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->create(['recorded_at' => now()->subDays(5)]);
        DeviceLocation::factory()->for($device)->create(['recorded_at' => now()->subDays(2)]);
        DeviceLocation::factory()->for($device)->create(['recorded_at' => now()]);

        $from = now()->subDays(3)->toDateTimeString();
        $to = now()->toDateTimeString();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations?from={$from}&to={$to}"))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('filters locations by manual flag', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->count(2)->create(['is_manual' => true]);
        DeviceLocation::factory()->for($device)->count(3)->create(['is_manual' => false]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations?is_manual=true"))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('paginates results when per_page is provided', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->count(25)->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations?per_page=10"))
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'total', 'per_page'],
            ]);
    });

    it('limits results to 100 by default without pagination', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->count(150)->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations"))
            ->assertOk()
            ->assertJsonCount(100, 'data');
    });

    it('prevents non-owner from viewing location history', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($otherUser, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations"))
            ->assertForbidden();
    });

    it('allows admin to view any device location history', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->count(3)->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations"))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });
});

describe('Device Latest Location', function () {
    it('returns the latest location for a device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->create(['recorded_at' => now()->subHour()]);
        $latest = DeviceLocation::factory()->for($device)->create(['recorded_at' => now()]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations/latest"))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $latest->id,
                ],
            ]);
    });

    it('uses cached last_location when available', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $location = DeviceLocation::factory()->create();
        $device = Device::factory()
            ->for($user, 'owner')
            ->for($category, 'category')
            ->create(['last_location_id' => $location->id]);

        $location->update(['device_id' => $device->id]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations/latest"))
            ->assertOk()
            ->assertJsonPath('data.id', $location->id);
    });

    it('returns null data when device has no locations', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations/latest"))
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('success', true);
    });

    it('prevents non-owner from viewing latest location', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($otherUser, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations/latest"))
            ->assertForbidden();
    });
});

describe('Manual Location Entry', function () {
    it('allows owner to add manual location', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/locations"), [
                'latitude' => -13.9626,
                'longitude' => 33.7741,
                'address' => 'Lilongwe, Malawi',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'latitude', 'longitude', 'address', 'is_manual', 'recorded_at'],
            ])
            ->assertJsonPath('data.is_manual', true);

        $this->assertDatabaseHas('device_locations', [
            'device_id' => $device->id,
            'latitude' => -13.9626,
            'longitude' => 33.7741,
            'is_manual' => true,
        ]);
    });

    it('updates device last_location after manual entry', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/locations"), [
                'latitude' => -15.7667,
                'longitude' => 35.0168,
            ])
            ->assertCreated();

        $device->refresh();
        expect($device->last_location_id)->not->toBeNull()
            ->and($device->last_seen_at)->not->toBeNull();
    });

    it('validates latitude range', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/locations"), [
                'latitude' => 100, // Invalid: must be between -90 and 90
                'longitude' => 33.7741,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);
    });

    it('validates longitude range', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/locations"), [
                'latitude' => -13.9626,
                'longitude' => 200, // Invalid: must be between -180 and 180
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['longitude']);
    });

    it('validates required fields', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/locations"), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    });

    it('prevents non-owner from adding location', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($otherUser, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/locations"), [
                'latitude' => -13.9626,
                'longitude' => 33.7741,
            ])
            ->assertForbidden();
    });

    it('accepts optional address and notes', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/locations"), [
                'latitude' => -13.9626,
                'longitude' => 33.7741,
                'address' => 'City Center Mall, Lilongwe',
                'notes' => 'Left at the food court',
            ])
            ->assertCreated()
            ->assertJsonPath('data.address', 'City Center Mall, Lilongwe');
    });
});

describe('Location Statistics', function () {
    it('returns location statistics for a device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->count(5)->create([
            'speed' => 10.5,
            'is_manual' => false,
        ]);
        DeviceLocation::factory()->for($device)->count(2)->create([
            'is_manual' => true,
        ]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations/statistics"))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_locations',
                    'manual_locations',
                    'automatic_locations',
                    'total_distance_meters',
                    'total_distance_km',
                    'average_speed_kmh',
                    'max_speed_kmh',
                ],
            ])
            ->assertJsonPath('data.total_locations', 7)
            ->assertJsonPath('data.manual_locations', 2)
            ->assertJsonPath('data.automatic_locations', 5);
    });

    it('filters statistics by date range', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->create(['recorded_at' => now()->subDays(5)]);
        DeviceLocation::factory()->for($device)->count(3)->create(['recorded_at' => now()]);

        $from = now()->subDays(2)->toDateString();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations/statistics?from={$from}"))
            ->assertOk()
            ->assertJsonPath('data.total_locations', 3);
    });
});

describe('Location Heatmap', function () {
    it('returns heatmap data for a device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        // Create multiple locations at same approximate position
        DeviceLocation::factory()->for($device)->count(5)->create([
            'latitude' => -13.9626,
            'longitude' => 33.7741,
        ]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations/heatmap"))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_points',
                    'clusters',
                    'data' => [
                        '*' => ['latitude', 'longitude', 'intensity'],
                    ],
                ],
            ])
            ->assertJsonPath('data.total_points', 5);
    });
});

describe('Location Track', function () {
    it('returns track data for playback', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        DeviceLocation::factory()->for($device)->create([
            'latitude' => -13.9620,
            'longitude' => 33.7740,
            'recorded_at' => now()->subHours(2),
        ]);
        DeviceLocation::factory()->for($device)->create([
            'latitude' => -13.9630,
            'longitude' => 33.7750,
            'recorded_at' => now()->subHour(),
        ]);
        DeviceLocation::factory()->for($device)->create([
            'latitude' => -13.9640,
            'longitude' => 33.7760,
            'recorded_at' => now(),
        ]);

        $from = now()->subHours(3)->toDateTimeString();
        $to = now()->toDateTimeString();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations/track?from={$from}&to={$to}"))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'device_id',
                    'device_name',
                    'total_points',
                    'total_distance_meters',
                    'total_distance_km',
                    'track' => [
                        '*' => ['latitude', 'longitude', 'recorded_at', 'distance_from_previous'],
                    ],
                ],
            ])
            ->assertJsonPath('data.total_points', 3);
    });

    it('requires date range parameters', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/locations/track"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to']);
    });
});
