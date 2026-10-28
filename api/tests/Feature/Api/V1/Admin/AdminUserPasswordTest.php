<?php

use App\Models\Branch;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'customer_service', 'guard_name' => 'api']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    $this->branch = Branch::create([
        'name' => 'Test Branch',
        'code' => 'TST',
        'city' => 'Lilongwe',
        'is_active' => true,
    ]);

    $this->storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->storeAdmin->assignRole('store_admin');

    $this->customer = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'customer@example.com',
    ]);
    $this->customer->assignRole('customer');
});

describe('User type filter', function () {
    it('returns only staff accounts for type=staff', function () {
        $staff = User::factory()->create(['branch_id' => $this->branch->id]);
        $staff->assignRole('customer_service');

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?type=staff&per_page=100');

        $response->assertSuccessful();

        $ids = collect($response->json('data'))->pluck('id');

        expect($ids)->toContain($this->storeAdmin->id)
            ->and($ids)->toContain($staff->id)
            ->and($ids)->not->toContain($this->customer->id);
    });

    it('returns only non-staff accounts for type=customer', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?type=customer&per_page=100');

        $response->assertSuccessful();

        $ids = collect($response->json('data'))->pluck('id');

        expect($ids)->toContain($this->customer->id)
            ->and($ids)->not->toContain($this->storeAdmin->id)
            ->and($ids)->not->toContain($this->admin->id);
    });

    it('counts role-less legacy accounts as customers', function () {
        $roleless = User::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?type=customer&per_page=100');

        $response->assertSuccessful();

        expect(collect($response->json('data'))->pluck('id'))->toContain($roleless->id);
    });

    it('combines the type and role filters', function () {
        $cs = User::factory()->create(['branch_id' => $this->branch->id]);
        $cs->assignRole('customer_service');

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?type=staff&role=customer_service&per_page=100');

        $response->assertSuccessful();

        $ids = collect($response->json('data'))->pluck('id');

        expect($ids)->toContain($cs->id)
            ->and($ids)->not->toContain($this->storeAdmin->id);
    });

    it('rejects an unknown type value', function () {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?type=manager')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    });

    it('exposes suspended state and staff flag to the admin UI', function () {
        $this->customer->update(['suspended_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/users/{$this->customer->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.is_suspended', true)
            ->assertJsonPath('data.is_staff', false);
    });
});

describe('Admin-initiated reset link', function () {
    it('emails a reset link to the user', function () {
        Notification::fake();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/send-password-reset")
            ->assertSuccessful()
            ->assertJsonPath('data.emailed', true);

        Notification::assertSentTo($this->customer, ResetPasswordNotification::class);
        expect(DB::table('password_reset_tokens')->where('email', $this->customer->email)->exists())->toBeTrue();
    });

    it('refuses when the user has no email address', function () {
        $noEmail = User::factory()->create(['branch_id' => $this->branch->id, 'email' => null]);
        $noEmail->assignRole('customer');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$noEmail->id}/send-password-reset")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    });

    it('refuses for a suspended account', function () {
        $this->customer->update(['suspended_at' => now()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/send-password-reset")
            ->assertStatus(422);
    });

    it('forbids a customer from triggering it', function () {
        $this->actingAs($this->customer)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/send-password-reset")
            ->assertForbidden();
    });

    it('forbids a store admin from resetting a peer store admin', function () {
        $peer = User::factory()->create(['branch_id' => $this->branch->id]);
        $peer->assignRole('store_admin');

        $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/admin/users/{$peer->id}/send-password-reset")
            ->assertForbidden();
    });

    it('forbids a store admin from reaching another branch', function () {
        $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTH', 'is_active' => true]);
        $outsider = User::factory()->create(['branch_id' => $otherBranch->id]);
        $outsider->assignRole('customer');

        $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/admin/users/{$outsider->id}/send-password-reset")
            ->assertForbidden();
    });
});

describe('Admin-set password', function () {
    it('sets the password, revokes sessions and emails the new password', function () {
        Notification::fake();
        $this->customer->createToken('phone');
        $oldHash = $this->customer->password;

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.emailed', true);

        $fresh = $this->customer->fresh();

        expect($fresh->password)->not->toBe($oldHash)
            ->and(Hash::check('BrandNewPass123!', $fresh->password))->toBeTrue()
            ->and($fresh->tokens()->count())->toBe(0);

        Notification::assertSentTo(
            $this->customer,
            PasswordChangedNotification::class,
            fn (PasswordChangedNotification $notification) => $notification->plainPassword === 'BrandNewPass123!'
        );
    });

    it('invalidates any reset link that was already emailed', function () {
        DB::table('password_reset_tokens')->insert([
            'email' => $this->customer->email,
            'token' => Hash::make('stale-token'),
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
            ])
            ->assertSuccessful();

        expect(DB::table('password_reset_tokens')->where('email', $this->customer->email)->exists())->toBeFalse();
    });

    it('skips the email when notify is false', function () {
        Notification::fake();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
                'notify' => false,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.emailed', false)
            ->assertJsonPath('message', 'Password updated. No notification was sent.');

        Notification::assertNothingSent();
    });

    it('keeps sessions alive when revoke_sessions is false', function () {
        $this->customer->createToken('phone');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
                'revoke_sessions' => false,
                'notify' => false,
            ])
            ->assertSuccessful();

        expect($this->customer->tokens()->count())->toBe(1);
    });

    it('reports no email sent for an account without an email address', function () {
        $noEmail = User::factory()->create(['branch_id' => $this->branch->id, 'email' => null]);
        $noEmail->assignRole('customer');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$noEmail->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.emailed', false);

        expect(Hash::check('BrandNewPass123!', $noEmail->fresh()->password))->toBeTrue();
    });

    it('validates password length and confirmation', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/reset-password", [
                'password' => 'short',
                'password_confirmation' => 'mismatch',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('refuses to reset your own password through the admin endpoint', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->admin->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
            ])
            ->assertForbidden();
    });

    it('forbids a customer from setting another password', function () {
        $victim = User::factory()->create(['branch_id' => $this->branch->id]);
        $victim->assignRole('customer');

        $this->actingAs($this->customer)
            ->postJson("/api/v1/admin/users/{$victim->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
            ])
            ->assertForbidden();
    });

    it('forbids a store admin from resetting a peer store admin', function () {
        $peer = User::factory()->create(['branch_id' => $this->branch->id]);
        $peer->assignRole('store_admin');
        $original = $peer->password;

        $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/admin/users/{$peer->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
            ])
            ->assertForbidden();

        expect($peer->fresh()->password)->toBe($original);
    });

    it('lets a store admin reset a customer in their own branch', function () {
        $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
                'notify' => false,
            ])
            ->assertSuccessful();

        expect(Hash::check('BrandNewPass123!', $this->customer->fresh()->password))->toBeTrue();
    });
});

describe('Mail transport failure', function () {
    // QUEUE_CONNECTION is sync, so a broken transport throws inline. The
    // password change must survive that; only the notification is lost.
    beforeEach(function () {
        config(['mail.default' => 'definitely-not-a-real-transport']);
    });

    it('keeps the password change and reports no email', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/reset-password", [
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.emailed', false);

        expect(Hash::check('BrandNewPass123!', $this->customer->fresh()->password))->toBeTrue();
    });

    it('answers the reset-link request with an actionable error', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/send-password-reset")
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    });
});

describe('Password change through the update endpoint', function () {
    it('revokes sessions and notifies when the update carries a password', function () {
        Notification::fake();
        $this->customer->createToken('phone');

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/users/{$this->customer->id}", [
                'name' => 'Renamed Customer',
                'password' => 'BrandNewPass123!',
            ])
            ->assertSuccessful();

        expect(Hash::check('BrandNewPass123!', $this->customer->fresh()->password))->toBeTrue()
            ->and($this->customer->tokens()->count())->toBe(0);

        Notification::assertSentTo($this->customer, PasswordChangedNotification::class);
    });

    it('does not notify when the update leaves the password alone', function () {
        Notification::fake();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/users/{$this->customer->id}", ['name' => 'Renamed Customer'])
            ->assertSuccessful();

        Notification::assertNothingSent();
    });
});
