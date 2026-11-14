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

    $this->branch = Branch::create([
        'name' => 'Test Branch',
        'code' => 'TST',
        'city' => 'Lilongwe',
        'is_active' => true,
    ]);

    $this->storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->storeAdmin->assignRole('store_admin');

    $this->customer = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->customer->assignRole('customer');
});

/**
 * A second store admin in the given branch - the peer whose account a store
 * admin must not be able to seize.
 *
 * Declared at file scope rather than inside a describe() closure: a named
 * function declared inside a closure is hoisted into the global scope, so it
 * would fatal with "cannot redeclare" if the closure were ever evaluated twice.
 */
function mufassahPeerStoreAdminIn(Branch $branch): User
{
    $peer = User::factory()->create(['branch_id' => $branch->id]);
    $peer->assignRole('store_admin');

    return $peer;
}

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

    it('scopes store admin users and blocks direct cross-branch access', function () {
        $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTH', 'is_active' => true]);
        $otherCustomer = User::factory()->create(['branch_id' => $otherBranch->id]);
        $otherCustomer->assignRole('customer');

        $this->actingAs($this->storeAdmin)
            ->getJson('/api/v1/admin/users?per_page=100')
            ->assertSuccessful()
            ->assertJsonMissing(['id' => $otherCustomer->id]);

        $this->actingAs($this->storeAdmin)
            ->getJson("/api/v1/admin/users/{$otherCustomer->id}")
            ->assertForbidden();
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

    it('refuses a phone number that already belongs to another account', function () {
        // The column has a unique index. Without a validation rule the insert
        // reached MySQL and came back as a 500, which front-desk staff saw as
        // "something went wrong" rather than "this number is already in use".
        $existing = User::factory()->create([
            'branch_id' => $this->branch->id,
            'phone_number' => '0891488676',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Second Customer',
                'phone_number' => '0891488676',
                'role' => 'customer',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number']);

        expect(User::where('phone_number', '0891488676')->count())->toBe(1)
            ->and($existing->phone_number)->toBe('0891488676');
    });

    it('stores a blank phone number as null so two walk-ins do not collide', function () {
        // An empty string would occupy the unique index once; the second blank
        // walk-in would then fail even though neither has a number.
        foreach (['Walk In One', 'Walk In Two'] as $name) {
            $this->actingAs($this->admin)
                ->postJson('/api/v1/admin/users', [
                    'name' => $name,
                    'phone_number' => '',
                    'role' => 'customer',
                ])
                ->assertCreated();
        }

        expect(User::where('name', 'Walk In One')->first()?->phone_number)->toBeNull()
            ->and(User::where('name', 'Walk In Two')->first()?->phone_number)->toBeNull();
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

    it('rejects another account\'s phone number but accepts its own', function () {
        $this->customer->update(['phone_number' => '0999116161']);
        $other = User::factory()->create([
            'branch_id' => $this->branch->id,
            'phone_number' => '0882913032',
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/users/{$this->customer->id}", [
                'phone_number' => '0882913032',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number']);

        // Saving the form again without changing the number must not trip the
        // unique rule on the user's own row.
        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/users/{$this->customer->id}", [
                'name' => 'Same Number',
                'phone_number' => '0999116161',
            ])
            ->assertSuccessful();

        expect($this->customer->fresh()->phone_number)->toBe('0999116161')
            ->and($other->fresh()->phone_number)->toBe('0882913032');
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

describe('Privilege Boundaries', function () {
    it('prevents a store admin from changing a peer store admin password', function () {
        $peer = mufassahPeerStoreAdminIn($this->branch);
        $originalPassword = $peer->password;

        $this->actingAs($this->storeAdmin)
            ->putJson("/api/v1/admin/users/{$peer->id}", [
                'password' => 'HijackedPassword123!',
            ])
            ->assertForbidden();

        // The blocked attempt must not have written anything.
        expect($peer->fresh()->password)->toBe($originalPassword);
    });

    it('prevents a store admin from changing a peer store admin email', function () {
        $peer = mufassahPeerStoreAdminIn($this->branch);
        $originalEmail = $peer->email;

        $this->actingAs($this->storeAdmin)
            ->putJson("/api/v1/admin/users/{$peer->id}", [
                'email' => 'attacker-controlled@example.test',
            ])
            ->assertForbidden();

        expect($peer->fresh()->email)->toBe($originalEmail);
    });

    it('prevents a store admin from touching a super admin in their branch', function () {
        $superAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($this->storeAdmin)
            ->putJson("/api/v1/admin/users/{$superAdmin->id}", [
                'password' => 'HijackedPassword123!',
            ])
            ->assertForbidden();
    });

    it('still lets a store admin manage a customer in their branch', function () {
        $this->actingAs($this->storeAdmin)
            ->putJson("/api/v1/admin/users/{$this->customer->id}", [
                'name' => 'Renamed By Store Admin',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Renamed By Store Admin');
    });

    it('prevents a store admin from suspending a peer store admin', function () {
        $peer = mufassahPeerStoreAdminIn($this->branch);

        $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/admin/users/{$peer->id}/suspend")
            ->assertForbidden();

        expect($peer->fresh()->suspended_at)->toBeNull();
    });

    it('prevents a store admin from unsuspending a peer store admin', function () {
        $peer = mufassahPeerStoreAdminIn($this->branch);
        $peer->update(['suspended_at' => now()]);

        // Previously this endpoint had no rank check at all.
        $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/admin/users/{$peer->id}/unsuspend")
            ->assertForbidden();

        expect($peer->fresh()->suspended_at)->not->toBeNull();
    });

    it('prevents a store admin from re-roling a peer store admin', function () {
        $peer = mufassahPeerStoreAdminIn($this->branch);

        $this->actingAs($this->storeAdmin)
            ->postJson("/api/v1/admin/users/{$peer->id}/assign-role", [
                'role' => 'customer',
            ])
            ->assertForbidden();

        expect($peer->fresh()->hasRole('store_admin'))->toBeTrue();
    });

    it('prevents a store admin from deleting a peer store admin', function () {
        $peer = mufassahPeerStoreAdminIn($this->branch);
        $peerId = $peer->id;

        $this->actingAs($this->storeAdmin)
            ->deleteJson("/api/v1/admin/users/{$peerId}")
            ->assertForbidden();

        expect(User::find($peerId))->not->toBeNull();
    });

    it('lets a super admin still manage a store admin', function () {
        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/users/{$this->storeAdmin->id}", [
                'name' => 'Renamed By Super Admin',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Renamed By Super Admin');
    });
});

/**
 * The layby desk creates walk-in customers through this endpoint.
 *
 * The dialog posts the identity fields the operator filled in and nothing else,
 * sending `null` for the optional ones, so the contract has to keep accepting
 * that shape without falling back to "email is required".
 */
describe('Walk-in customer from the counter', function () {
    it('accepts the minimal payload a store admin sends when opening a layby', function () {
        $this->actingAs($this->storeAdmin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Walk-in Customer',
                'phone_number' => '+265999111222',
                'email' => null,
                'national_id' => null,
                'role' => 'customer',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Walk-in Customer')
            ->assertJsonPath('data.phone_number', '+265999111222');

        $customer = User::where('phone_number', '+265999111222')->first();

        expect($customer)->not->toBeNull()
            ->and($customer->hasRole('customer'))->toBeTrue()
            // The branch comes from the actor, not from the payload.
            ->and($customer->branch_id)->toBe($this->branch->id);
    });

    it('reports a duplicate phone number as a field error so the desk can search instead', function () {
        User::factory()->create(['phone_number' => '+265999333444']);

        $this->actingAs($this->storeAdmin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Duplicate',
                'phone_number' => '+265999333444',
                'role' => 'customer',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone_number');
    });
});
