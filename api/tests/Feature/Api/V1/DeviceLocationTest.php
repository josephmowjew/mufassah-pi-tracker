<?php

use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\DeviceLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
});

/**
 * Build an owned device that already has a last_location_id, mirroring what the
 * IoT ingestion path always does in production.
 *
 * Declared at file scope rather than inside a describe() closure: a named
 * function declared inside a closure is hoisted into the global scope, so it
 * would fatal with "cannot redeclare" if the closure were ever evaluated twice.
 *
 * @return array{0: Device, 1: DeviceLocation}
 */
function mufassahOwnedDeviceWithLocation(User $owner, DeviceCategory $category, array $attributes = []): array
{
    $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create($attributes);
    $location = DeviceLocation::factory()->for($device)->create();

    $device->update(['last_location_id' => $location->id]);

    return [$device->refresh(), $location];
}

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

describe('Batch Latest Locations', function () {
    it('returns the latest location for several devices in one request', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        [$deviceA, $locationA] = mufassahOwnedDeviceWithLocation($user, $category);
        [$deviceB, $locationB] = mufassahOwnedDeviceWithLocation($user, $category);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), [
                'device_ids' => [$deviceA->id, $deviceB->id],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath("data.{$deviceA->id}.id", $locationA->id)
            ->assertJsonPath("data.{$deviceB->id}.id", $locationB->id);
    });

    it('keys the response by device id and maps devices with no location to null', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        [$withLocation, $location] = mufassahOwnedDeviceWithLocation($user, $category);
        $withoutLocation = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), [
                'device_ids' => [$withLocation->id, $withoutLocation->id],
            ])
            ->assertOk()
            ->assertJsonPath("data.{$withLocation->id}.id", $location->id)
            ->assertJsonPath("data.{$withoutLocation->id}", null);
    });

    it('omits devices the caller cannot see', function () {
        $owner = User::factory()->create();
        $owner->assignRole('customer');
        $stranger = User::factory()->create();
        $stranger->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        [$mine] = mufassahOwnedDeviceWithLocation($owner, $category);
        [$theirs] = mufassahOwnedDeviceWithLocation($stranger, $category);

        $response = actingAs($owner, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), [
                'device_ids' => [$mine->id, $theirs->id],
            ])
            ->assertOk();

        // The readable device is returned; the other user's device is not
        // leaked and does not fail the whole batch.
        $response->assertJsonPath("data.{$mine->id}.id", $mine->last_location_id);
        $response->assertJsonMissingPath("data.{$theirs->id}");
    });

    it('returns an empty set when every requested device is inaccessible', function () {
        $stranger = User::factory()->create();
        $stranger->assignRole('customer');
        $requester = User::factory()->create();
        $requester->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        [$theirs] = mufassahOwnedDeviceWithLocation($stranger, $category);

        actingAs($requester, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), ['device_ids' => [$theirs->id]])
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('lets a super admin read devices they do not own', function () {
        $owner = User::factory()->create();
        $owner->assignRole('customer');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $category = DeviceCategory::factory()->create();

        [$device, $location] = mufassahOwnedDeviceWithLocation($owner, $category);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), ['device_ids' => [$device->id]])
            ->assertOk()
            ->assertJsonPath("data.{$device->id}.id", $location->id);
    });

    it('falls back to the newest location when last_location_id was never set', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        // Legacy row: locations exist but the denormalised pointer is null.
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')
            ->create(['last_location_id' => null]);

        DeviceLocation::factory()->for($device)->create(['recorded_at' => now()->subDay()]);
        $newest = DeviceLocation::factory()->for($device)->create(['recorded_at' => now()]);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), ['device_ids' => [$device->id]])
            ->assertOk()
            ->assertJsonPath("data.{$device->id}.id", $newest->id);
    });

    it('keeps the query count constant as the device count grows', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        $devices = collect(range(1, 5))
            ->map(fn () => mufassahOwnedDeviceWithLocation($user, $category)[0]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), ['device_ids' => $devices->pluck('id')->all()])
            ->assertOk();
        $smallCount = count(DB::getQueryLog());

        // Five times as many devices must not mean five times the queries.
        $more = collect(range(1, 25))
            ->map(fn () => mufassahOwnedDeviceWithLocation($user, $category)[0]);

        DB::flushQueryLog();
        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), [
                'device_ids' => $devices->concat($more)->pluck('id')->all(),
            ])
            ->assertOk();
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A per-device implementation would add 20+ queries here.
        expect($largeCount)->toBeLessThanOrEqual($smallCount + 2);
    });

    it('deduplicates repeated device ids', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        [$device, $location] = mufassahOwnedDeviceWithLocation($user, $category);

        $response = actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), [
                'device_ids' => [$device->id, $device->id, $device->id],
            ])
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json("data.{$device->id}.id"))->toBe($location->id);
    });

    it('returns an empty set for an empty device list', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), ['device_ids' => []])
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('requires device_ids', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_ids']);
    });

    it('rejects more than 200 device ids', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices/locations/latest'), [
                'device_ids' => range(1, 201),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_ids']);
    });

    it('requires authentication', function () {
        $this->postJson(apiUrl('devices/locations/latest'), ['device_ids' => [1]])
            ->assertUnauthorized();
    });
});
