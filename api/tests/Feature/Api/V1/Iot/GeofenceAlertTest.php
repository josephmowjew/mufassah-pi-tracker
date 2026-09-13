<?php

use App\Enums\DeviceStatus;
use App\Enums\GeofenceEventType;
use App\Enums\IotModuleStatus;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\IotModule;
use App\Models\User;
use App\Notifications\GeofenceViolationNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

/**
 * Geofence entry/exit alerts exercised through the real IoT ingest endpoint.
 *
 * The previous suite built GeofenceEvent rows with a factory and never called
 * POST /api/v1/iot/location, which is where checkGeofenceViolation lives. That
 * is why a broken column list and a wrong-arity notification call went unnoticed.
 */
beforeEach(function () {
    Notification::fake();

    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);

    $this->user = User::factory()->create();
    $this->user->assignRole('customer');

    $this->category = DeviceCategory::factory()->create();

    $this->plainToken = 'geofence-test-token-12345';
    $this->module = IotModule::factory()->create([
        'module_id' => 'MOD-GEO-001',
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

    $this->authHeader = "Bearer {$this->module->module_id}:{$this->plainToken}";

    // Geofence centred on a fixed point in Malawi with a 1 km radius.
    $this->center = ['lat' => -13.9626, 'lng' => 33.7741];

    $this->geofence = Geofence::factory()
        ->for($this->device, 'device')
        ->create([
            'name' => 'Home Zone',
            'center_latitude' => $this->center['lat'],
            'center_longitude' => $this->center['lng'],
            'radius_meters' => 1000,
            'is_active' => true,
            'notify_on_entry' => true,
            'notify_on_exit' => true,
        ]);
});

/** Post a location to the real ingest endpoint. */
function postLocation(string $authHeader, float $lat, float $lng)
{
    return test()->postJson(apiUrl('iot/location'), [
        'latitude' => $lat,
        'longitude' => $lng,
        'recorded_at' => now()->toIso8601String(),
    ], ['Authorization' => $authHeader]);
}

describe('Geofence Entry Alerts', function () {
    it('creates an entry event when a device enters a geofence', function () {
        postLocation($this->authHeader, $this->center['lat'], $this->center['lng'])
            ->assertCreated();

        // The event row must satisfy the NOT NULL columns on geofence_events.
        $event = GeofenceEvent::first();

        expect($event)->not->toBeNull()
            ->and($event->geofence_id)->toBe($this->geofence->id)
            ->and($event->device_id)->toBe($this->device->id)
            ->and($event->event_type)->toBe(GeofenceEventType::Entry)
            ->and((float) $event->distance_from_center)->toBeLessThan(1.0)
            ->and($event->event_time)->not->toBeNull();

        // The location must be linked, not left null.
        expect($event->location_id)->not->toBeNull();
    });

    it('notifies the device owner with the geofence attached', function () {
        postLocation($this->authHeader, $this->center['lat'], $this->center['lng'])
            ->assertCreated();

        Notification::assertSentTo(
            $this->user,
            GeofenceViolationNotification::class,
            function (GeofenceViolationNotification $notification) {
                // Constructor requires both arguments; this fails if only one is passed.
                return $notification->geofence->id === $this->geofence->id
                    && $notification->event->geofence_id === $this->geofence->id;
            }
        );
    });

    it('does not create a second entry event while the device stays inside', function () {
        postLocation($this->authHeader, $this->center['lat'], $this->center['lng'])
            ->assertCreated();

        postLocation($this->authHeader, $this->center['lat'] + 0.0005, $this->center['lng'])
            ->assertCreated();

        expect(GeofenceEvent::where('event_type', GeofenceEventType::Entry)->count())->toBe(1);
    });

    it('records the distance from the geofence centre', function () {
        // ~555 m north of the centre (0.005 degrees latitude is roughly 555 m).
        postLocation($this->authHeader, $this->center['lat'] + 0.005, $this->center['lng'])
            ->assertCreated();

        $distance = (float) GeofenceEvent::first()->distance_from_center;

        expect($distance)->toBeGreaterThan(500)->toBeLessThan(600);
    });
});

describe('Geofence Exit Alerts', function () {
    it('creates an exit event when a device leaves a geofence', function () {
        // Enter first so there is a known inside state.
        postLocation($this->authHeader, $this->center['lat'], $this->center['lng'])
            ->assertCreated();

        // Then move well outside the 1 km radius (~5.5 km north).
        postLocation($this->authHeader, $this->center['lat'] + 0.05, $this->center['lng'])
            ->assertCreated();

        expect(GeofenceEvent::where('event_type', GeofenceEventType::Entry)->count())->toBe(1)
            ->and(GeofenceEvent::where('event_type', GeofenceEventType::Exit)->count())->toBe(1);
    });

    it('creates no event for a location that is outside from the start', function () {
        postLocation($this->authHeader, $this->center['lat'] + 0.05, $this->center['lng'])
            ->assertCreated();

        expect(GeofenceEvent::count())->toBe(0);
        Notification::assertNothingSent();
    });
});

describe('Geofence Alert Suppression', function () {
    it('creates no entry event when entry notifications are disabled', function () {
        $this->geofence->update(['notify_on_entry' => false]);

        postLocation($this->authHeader, $this->center['lat'], $this->center['lng'])
            ->assertCreated();

        expect(GeofenceEvent::count())->toBe(0);
        Notification::assertNothingSent();
    });

    it('ignores inactive geofences', function () {
        $this->geofence->update(['is_active' => false]);

        postLocation($this->authHeader, $this->center['lat'], $this->center['lng'])
            ->assertCreated();

        expect(GeofenceEvent::count())->toBe(0);
    });
});
