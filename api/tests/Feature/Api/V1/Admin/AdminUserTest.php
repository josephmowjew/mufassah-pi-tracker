<?php

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'customer_service', 'guard_name' => 'api']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    $this->storeAdmin = User::factory()->create();
    $this->storeAdmin->assignRole('store_admin');

    $this->customer = User::factory()->create();
    $this->customer->assignRole('customer');

    $this->branch = Branch::create([
        'name' => 'Test Branch',
        'code' => 'TST',
        'city' => 'Lilongwe',
        'is_active' => true,
    ]);
});

describe('User Listing', function () {
    it('allows admin to list users', function () {
        User::factory()->count(5)->create()->each(fn ($u) => $u->assignRole('customer'));

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'email', 'roles'],
                ],
                'meta' => ['total'],
            ]);
    });

    it('filters users by search term', function () {
        User::factory()->create(['name' => 'Zephyr Unique', 'email' => 'zephyr@unique-domain.test']);
        User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?search=zephyr');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 1);
    });

    it('filters users by role', function () {
        User::factory()->count(3)->create()->each(fn ($u) => $u->assignRole('customer'));

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?role=customer');

        $response->assertSuccessful();
        expect($response->json('meta.total'))->toBeGreaterThanOrEqual(4); // 3 new + 1 from setup
    });

    it('prevents customer from listing users', function () {
        $response = $this->actingAs($this->customer)
            ->getJson('/api/v1/admin/users');

        $response->assertForbidden();
    });
});

describe('User Creation', function () {
    it('allows admin to create user', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'password' => 'Password123!',
                'role' => 'customer',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New User')
            ->assertJsonPath('data.email', 'newuser@example.com');

        expect(User::where('email', 'newuser@example.com')->exists())->toBeTrue();
    });

    it('allows admin to create customer without email or password', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Walk In Customer',
                'phone_number' => '+265999000111',
                'role' => 'customer',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Walk In Customer')
            ->assertJsonPath('data.email', null);

        $customer = User::where('phone_number', '+265999000111')->first();
        expect($customer)->not->toBeNull()
            ->and($customer->email)->toBeNull()
            ->and($customer->hasRole('customer'))->toBeTrue();
    });

    it('requires email and password when admin creates non-customer users', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Staff Without Login',
                'role' => 'customer_service',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    });

    it('creates user with verified email when specified', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Verified User',
                'email' => 'verified@example.com',
                'password' => 'Password123!',
                'role' => 'customer',
                'verify_email' => true,
            ]);

        $response->assertCreated();
        $user = User::where('email', 'verified@example.com')->first();
        expect($user->hasVerifiedEmail())->toBeTrue();
    });

    it('creates user with selected branch', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Branch User',
                'email' => 'branchuser@example.com',
                'password' => 'Password123!',
                'role' => 'customer',
                'branch_id' => $this->branch->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.branch_id', $this->branch->id);

        expect(User::where('email', 'branchuser@example.com')->first()?->branch_id)->toBe($this->branch->id);
    });

    it('defaults new user branch to creator branch when branch is omitted', function () {
        $this->admin->update(['branch_id' => $this->branch->id]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Inherited Branch User',
                'email' => 'inherited@example.com',
                'password' => 'Password123!',
                'role' => 'customer',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.branch_id', $this->branch->id);

        expect(User::where('email', 'inherited@example.com')->first()?->branch_id)->toBe($this->branch->id);
    });

    it('validates required fields', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
    });

    it('validates unique email', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Duplicate',
                'email' => $this->customer->email,
                'password' => 'Password123!',
                'role' => 'customer',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });
});

describe('User View', function () {
    it('allows admin to view user details', function () {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/users/{$this->customer->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $this->customer->id)
            ->assertJsonStructure([
                'success',
                'data',
                'statistics' => [
                    'devices_count',
                    'subscriptions_count',
                    'theft_reports_count',
                ],
            ]);
    });
});

describe('User Update', function () {
    it('allows admin to update user', function () {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/users/{$this->customer->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated Name');

        expect($this->customer->fresh()->name)->toBe('Updated Name');
    });

    it('allows updating password', function () {
        $oldPassword = $this->customer->password;

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/users/{$this->customer->id}", [
                'password' => 'NewPassword123!',
            ]);

        $response->assertSuccessful();
        expect($this->customer->fresh()->password)->not->toBe($oldPassword);
    });
});

describe('User Deletion', function () {
    it('allows admin to delete customer', function () {
        $userId = $this->customer->id;

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/users/{$userId}");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'User deleted successfully.');

        expect(User::find($userId))->toBeNull();
    });

    it('prevents deleting super admin', function () {
        $anotherAdmin = User::factory()->create();
        $anotherAdmin->assignRole('super_admin');

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/users/{$anotherAdmin->id}");

        $response->assertForbidden()
            ->assertJsonPath('message', 'Cannot delete super admin users.');
    });

    it('prevents deleting own account', function () {
        // Use store_admin instead of super_admin to properly test self-deletion check
        $response = $this->actingAs($this->storeAdmin)
            ->deleteJson("/api/v1/admin/users/{$this->storeAdmin->id}");

        $response->assertForbidden()
            ->assertJsonPath('message', 'Cannot delete your own account.');
    });
});

describe('Role Assignment', function () {
    it('allows admin to assign role', function () {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/assign-role", [
                'role' => 'store_admin',
            ]);

        $response->assertSuccessful();
        expect($this->customer->fresh()->hasRole('store_admin'))->toBeTrue();
    });

    it('prevents non-super-admin from assigning super_admin role', function () {
        $response = $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/assign-role", [
                'role' => 'super_admin',
            ]);

        $response->assertForbidden();
    });

    it('validates role exists', function () {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/assign-role", [
                'role' => 'nonexistent_role',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    });
});

describe('User Suspension', function () {
    it('allows admin to suspend user', function () {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/suspend");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'User suspended successfully.');

        expect($this->customer->fresh()->suspended_at)->not->toBeNull();
    });

    it('prevents suspending super admin', function () {
        $anotherAdmin = User::factory()->create();
        $anotherAdmin->assignRole('super_admin');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$anotherAdmin->id}/suspend");

        $response->assertForbidden();
    });

    it('prevents suspending own account', function () {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->admin->id}/suspend");

        $response->assertForbidden();
    });

    it('allows admin to unsuspend user', function () {
        $this->customer->update(['suspended_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/unsuspend");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'User unsuspended successfully.');

        expect($this->customer->fresh()->suspended_at)->toBeNull();
    });
});

describe('Email Verification', function () {
    it('allows admin to verify user email', function () {
        $unverifiedUser = User::factory()->unverified()->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$unverifiedUser->id}/verify-email");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Email verified successfully.');

        expect($unverifiedUser->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('returns error if email already verified', function () {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->customer->id}/verify-email");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Email is already verified.');
    });
});

describe('Roles Listing', function () {
    it('returns available roles', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/roles');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name'],
                ],
            ]);
    });
});
