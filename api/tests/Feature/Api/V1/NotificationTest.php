<?php

use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TheftReport;
use App\Models\User;
use App\Notifications\DeviceRegisteredNotification;
use App\Notifications\PaymentConfirmedNotification;
use App\Notifications\SubscriptionExpiringNotification;
use App\Notifications\TheftReportSubmittedNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);

    $this->user = User::factory()->create();
    $this->user->assignRole('customer');
});

describe('Notification Listing', function () {
    it('returns notifications for authenticated user', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));
        $this->user->notify(new DeviceRegisteredNotification($device));

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 2);
    });

    it('returns empty list when user has no notifications', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 0);
    });

    it('filters by unread notifications', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));
        $notification = $this->user->notifications()->first();
        $notification->markAsRead();

        $this->user->notify(new DeviceRegisteredNotification($device));

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications?unread=true');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 1);
    });

    it('requires authentication', function () {
        $response = $this->getJson('/api/v1/notifications');

        $response->assertUnauthorized();
    });
});

describe('Unread Count', function () {
    it('returns unread notifications count', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));
        $this->user->notify(new DeviceRegisteredNotification($device));
        $this->user->notify(new DeviceRegisteredNotification($device));

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/unread-count');

        $response->assertSuccessful()
            ->assertJsonPath('data.unread_count', 3);
    });

    it('returns zero when all notifications are read', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));
        $this->user->notifications->markAsRead();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/unread-count');

        $response->assertSuccessful()
            ->assertJsonPath('data.unread_count', 0);
    });
});

describe('Show Notification', function () {
    it('allows user to view their notification', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));
        $notification = $this->user->notifications()->first();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'id', 'type', 'title', 'message', 'read_at', 'is_read', 'created_at',
            ]);
    });

    it('returns 404 for non-existent notification', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/non-existent-id');

        $response->assertNotFound();
    });

    it('prevents viewing another users notification', function () {
        $otherUser = User::factory()->create();
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($otherUser, 'owner')->create();

        $otherUser->notify(new DeviceRegisteredNotification($device));
        $notification = $otherUser->notifications()->first();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertNotFound();
    });
});

describe('Mark as Read', function () {
    it('marks notification as read', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));
        $notification = $this->user->notifications()->first();

        expect($notification->read_at)->toBeNull();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Notification marked as read.');

        expect($notification->fresh()->read_at)->not->toBeNull();
    });

    it('returns 404 for non-existent notification', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/notifications/non-existent-id/read');

        $response->assertNotFound();
    });
});

describe('Mark All as Read', function () {
    it('marks all notifications as read', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));
        $this->user->notify(new DeviceRegisteredNotification($device));
        $this->user->notify(new DeviceRegisteredNotification($device));

        expect($this->user->unreadNotifications()->count())->toBe(3);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/notifications/read-all');

        $response->assertSuccessful()
            ->assertJsonPath('message', 'All notifications marked as read.');

        expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
    });
});

describe('Delete Notification', function () {
    it('deletes a notification', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));
        $notification = $this->user->notifications()->first();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/notifications/{$notification->id}");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Notification deleted.');

        expect($this->user->notifications()->count())->toBe(0);
    });

    it('returns 404 for non-existent notification', function () {
        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/notifications/non-existent-id');

        $response->assertNotFound();
    });
});

describe('Delete All Notifications', function () {
    it('deletes all notifications for user', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));
        $this->user->notify(new DeviceRegisteredNotification($device));
        $this->user->notify(new DeviceRegisteredNotification($device));

        expect($this->user->notifications()->count())->toBe(3);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/notifications/delete-all');

        $response->assertSuccessful()
            ->assertJsonPath('message', 'All notifications deleted.');

        expect($this->user->notifications()->count())->toBe(0);
    });
});

describe('Notification Types', function () {
    it('creates device registered notification correctly', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        Notification::fake();

        $this->user->notify(new DeviceRegisteredNotification($device));

        Notification::assertSentTo($this->user, DeviceRegisteredNotification::class);
    });

    it('creates theft report submitted notification correctly', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();
        $theftReport = TheftReport::factory()->for($this->user, 'reporter')->for($device)->create();

        Notification::fake();

        $this->user->notify(new TheftReportSubmittedNotification($theftReport));

        Notification::assertSentTo($this->user, TheftReportSubmittedNotification::class);
    });

    it('creates payment confirmed notification correctly', function () {
        $payment = Payment::factory()->completed()->for($this->user)->create();

        Notification::fake();

        $this->user->notify(new PaymentConfirmedNotification($payment));

        Notification::assertSentTo($this->user, PaymentConfirmedNotification::class);
    });

    it('creates subscription expiring notification correctly', function () {
        $plan = SubscriptionPlan::factory()->basic()->create();
        $subscription = Subscription::factory()
            ->active()
            ->for($this->user)
            ->for($plan, 'plan')
            ->create();

        Notification::fake();

        $this->user->notify(new SubscriptionExpiringNotification($subscription, 7));

        Notification::assertSentTo($this->user, SubscriptionExpiringNotification::class);
    });
});

describe('Notification Data Structure', function () {
    it('has correct data structure for device notification', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->for($this->user, 'owner')->create();

        $this->user->notify(new DeviceRegisteredNotification($device));

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'notifications' => [
                        '*' => [
                            'id',
                            'type',
                            'title',
                            'message',
                            'data',
                            'read_at',
                            'is_read',
                            'created_at',
                        ],
                    ],
                ],
                'meta' => ['current_page', 'total'],
            ]);
    });
});
