<?php

use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\User;
use App\Notifications\GeofenceViolationNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);

    $this->user = User::factory()->create();
    $this->user->assignRole('customer');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->category = DeviceCategory::factory()->create();
    $this->device = Device::factory()->for($this->category, 'category')->for($this->user, 'owner')->create();
});

describe('User Geofence Listing', function () {
    it('returns all geofences for the authenticated user', function () {
        // Create geofences for this user's device
        Geofence::factory()->count(3)->for($this->device)->create();

        // Create another device for the same user with geofences
        $anotherDevice = Device::factory()->for($this->category, 'category')->for($this->user, 'owner')->create();
        Geofence::factory()->count(2)->for($anotherDevice)->create();

        // Create geofences for another user's device (should not be included)
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');
        $otherDevice = Device::factory()->for($this->category, 'category')->for($otherUser, 'owner')->create();
        Geofence::factory()->count(4)->for($otherDevice)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/geofences');

        $response->assertSuccessful()
            ->assertJsonCount(5, 'data'); // 3 + 2 = 5 geofences
    });

    it('filters user geofences by active status', function () {
        Geofence::factory()->count(2)->for($this->device)->create();
        Geofence::factory()->inactive()->for($this->device)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/geofences?active=true');

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data');
    });

    it('includes device relationship in geofence list', function () {
        Geofence::factory()->for($this->device)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/geofences');

        $response->assertSuccessful()
            ->assertJsonPath('data.0.device.id', $this->device->id);
    });
});

describe('Geofence Listing', function () {
    it('returns geofences for device owner', function () {
        Geofence::factory()->count(3)->for($this->device)->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/devices/{$this->device->id}/geofences");

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 3);
    });

    it('filters by active status', function () {
        Geofence::factory()->count(2)->for($this->device)->create();
        Geofence::factory()->inactive()->for($this->device)->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/devices/{$this->device->id}/geofences?active=true");

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 2);
    });

    it('prevents non-owner from listing geofences', function () {
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');

        $response = $this->actingAs($otherUser)
            ->getJson("/api/v1/devices/{$this->device->id}/geofences");

        $response->assertForbidden();
    });

    it('allows admin to list any device geofences', function () {
        Geofence::factory()->count(2)->for($this->device)->create();

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/devices/{$this->device->id}/geofences");

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 2);
    });
});

describe('Geofence Creation', function () {
    it('allows device owner to create circular geofence', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/geofences', [
                'device_id' => $this->device->id,
                'name' => 'Home Zone',
                'description' => 'My home area',
                'shape_type' => 'circle',
                'center_latitude' => -13.9626,
                'center_longitude' => 33.7741,
                'radius_meters' => 500,
                'notify_on_entry' => true,
                'notify_on_exit' => true,
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Home Zone')
            ->assertJsonPath('data.shape_type', 'circle')
            ->assertJsonPath('data.radius_meters', 500);

        expect(Geofence::where('device_id', $this->device->id)->exists())->toBeTrue();
    });

    it('validates required fields', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/geofences', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id', 'name', 'shape_type', 'center_latitude', 'center_longitude']);
    });

    it('validates radius for circular geofence', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/geofences', [
                'device_id' => $this->device->id,
                'name' => 'Test',
                'shape_type' => 'circle',
                'center_latitude' => -13.9626,
                'center_longitude' => 33.7741,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['radius_meters']);
    });

    it('prevents creating geofence for device not owned', function () {
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');

        $response = $this->actingAs($otherUser)
            ->postJson('/api/v1/geofences', [
                'device_id' => $this->device->id,
                'name' => 'Test',
                'shape_type' => 'circle',
                'center_latitude' => -13.9626,
                'center_longitude' => 33.7741,
                'radius_meters' => 500,
            ]);

        $response->assertForbidden();
    });

    it('allows admin to create geofence for any device', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/geofences', [
                'device_id' => $this->device->id,
                'name' => 'Admin Created Zone',
                'shape_type' => 'circle',
                'center_latitude' => -13.9626,
                'center_longitude' => 33.7741,
                'radius_meters' => 1000,
            ]);

        $response->assertSuccessful();
    });
});

describe('Geofence View', function () {
    it('allows owner to view geofence details', function () {
        $geofence = Geofence::factory()->for($this->device)->create(['name' => 'My Geofence']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/geofences/{$geofence->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'My Geofence');
    });

    it('prevents non-owner from viewing geofence', function () {
        $geofence = Geofence::factory()->for($this->device)->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');

        $response = $this->actingAs($otherUser)
            ->getJson("/api/v1/geofences/{$geofence->id}");

        $response->assertForbidden();
    });
});

describe('Geofence Update', function () {
    it('allows owner to update geofence', function () {
        $geofence = Geofence::factory()->for($this->device)->create();

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/geofences/{$geofence->id}", [
                'name' => 'Updated Name',
                'radius_meters' => 1000,
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.radius_meters', 1000);
    });

    it('prevents non-owner from updating geofence', function () {
        $geofence = Geofence::factory()->for($this->device)->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');

        $response = $this->actingAs($otherUser)
            ->putJson("/api/v1/geofences/{$geofence->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertForbidden();
    });
});

describe('Geofence Deletion', function () {
    it('allows owner to delete geofence', function () {
        $geofence = Geofence::factory()->for($this->device)->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/geofences/{$geofence->id}");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Geofence deleted successfully.');

        expect(Geofence::find($geofence->id))->toBeNull();
    });

    it('prevents non-owner from deleting geofence', function () {
        $geofence = Geofence::factory()->for($this->device)->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');

        $response = $this->actingAs($otherUser)
            ->deleteJson("/api/v1/geofences/{$geofence->id}");

        $response->assertForbidden();
    });
});

describe('Geofence Toggle', function () {
    it('toggles geofence active status', function () {
        $geofence = Geofence::factory()->for($this->device)->create(['is_active' => true]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/geofences/{$geofence->id}/toggle");

        $response->assertSuccessful()
            ->assertJsonPath('data.is_active', false);

        expect($geofence->fresh()->is_active)->toBeFalse();
    });

    it('toggles inactive to active', function () {
        $geofence = Geofence::factory()->inactive()->for($this->device)->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/geofences/{$geofence->id}/toggle");

        $response->assertSuccessful()
            ->assertJsonPath('data.is_active', true);
    });
});

describe('Geofence Events', function () {
    it('returns events for geofence', function () {
        $geofence = Geofence::factory()->for($this->device)->create();
        GeofenceEvent::factory()->count(5)->for($geofence)->for($this->device)->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/geofences/{$geofence->id}/events");

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 5);
    });

    it('filters events by type', function () {
        $geofence = Geofence::factory()->for($this->device)->create();
        GeofenceEvent::factory()->count(3)->entry()->for($geofence)->for($this->device)->create();
        GeofenceEvent::factory()->count(2)->exit()->for($geofence)->for($this->device)->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/geofences/{$geofence->id}/events?event_type=entry");

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 3);
    });
});

describe('Geofence Violation Notification', function () {
    it('creates geofence violation notification correctly', function () {
        $geofence = Geofence::factory()->for($this->device)->create();
        $event = GeofenceEvent::factory()->entry()->for($geofence)->for($this->device)->create();

        Notification::fake();

        $this->user->notify(new GeofenceViolationNotification($event, $geofence));

        Notification::assertSentTo($this->user, GeofenceViolationNotification::class);
    });
});
