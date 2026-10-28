<?php

use App\Http\Resources\AdminUserResource;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * UserResource is embedded in ~25 other resources, several of them
 * customer-facing. These tests pin the two properties that make that safe:
 * no PII on the shared resource, and no relation touched unless the caller
 * eager-loaded it.
 */
beforeEach(function () {
    foreach (['customer', 'super_admin', 'store_admin', 'customer_service'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'api']);
    }

    $this->branch = Branch::create(['name' => 'Exposure Branch', 'code' => 'EXP', 'is_active' => true]);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->customer = User::factory()->create([
        'branch_id' => $this->branch->id,
        'national_id' => 'MWI-SECRET-445566',
    ]);
    $this->customer->assignRole('customer');
});

describe('Shared UserResource exposure', function () {
    it('omits national_id when the relation was not eager-loaded', function () {
        $payload = (new UserResource($this->customer))->resolve();

        expect($payload)->not->toHaveKey('national_id');
    });

    it('omits roles and is_staff rather than lazy-loading the relation', function () {
        $payload = (new UserResource($this->customer))->resolve();

        expect($payload)->not->toHaveKey('roles')
            ->and($payload)->not->toHaveKey('is_staff');
    });

    it('omits suspension state from co-participants', function () {
        $this->customer->update(['suspended_at' => now()]);

        $payload = (new UserResource($this->customer))->resolve();

        expect($payload)->not->toHaveKey('suspended_at')
            ->and($payload)->not->toHaveKey('is_suspended');
    });

    it('derives is_staff from the relation when it is loaded', function () {
        $this->customer->load('roles');

        $payload = (new UserResource($this->customer))->resolve();

        expect($payload['is_staff'])->toBeFalse()
            ->and($payload['roles']->all())->toBe(['customer']);
    });

    it('keeps national_id off embedded users in an admin device listing', function () {
        $category = DeviceCategory::factory()->create();
        Device::factory()->create([
            'owner_id' => $this->customer->id,
            'category_id' => $category->id,
            'branch_id' => $this->branch->id,
        ]);

        $owner = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/devices?per_page=10')
            ->assertSuccessful()
            ->json('data.0.owner');

        expect($owner)->toBeArray()
            ->and($owner)->not->toHaveKey('national_id')
            ->and($owner)->not->toHaveKey('roles');
    });
});

describe('AdminUserResource exposure', function () {
    it('carries national_id for the admin user screens', function () {
        $payload = (new AdminUserResource($this->customer))->resolve();

        expect($payload['national_id'])->toBe('MWI-SECRET-445566');
    });

    it('carries suspension state for the admin user screens', function () {
        $this->customer->update(['suspended_at' => now()]);

        $payload = (new AdminUserResource($this->customer))->resolve();

        expect($payload['is_suspended'])->toBeTrue()
            ->and($payload['suspended_at'])->not->toBeNull();
    });

    it('is what the admin user endpoints actually return', function () {
        $this->customer->update(['suspended_at' => now()]);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/users/{$this->customer->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.national_id', 'MWI-SECRET-445566')
            ->assertJsonPath('data.is_suspended', true);

        $row = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?per_page=100')
            ->assertSuccessful()
            ->json('data');

        $target = collect($row)->firstWhere('id', $this->customer->id);

        expect($target)->not->toBeNull()
            ->and($target)->toHaveKey('national_id')
            ->and($target)->toHaveKey('is_staff');
    });
});

describe('Embedded user query cost', function () {
    it('does not run a role query per embedded user', function () {
        $category = DeviceCategory::factory()->create();

        foreach (range(1, 5) as $i) {
            $owner = User::factory()->create(['branch_id' => $this->branch->id]);
            $owner->assignRole('customer');
            Device::factory()->create([
                'owner_id' => $owner->id,
                'category_id' => $category->id,
                'branch_id' => $this->branch->id,
            ]);
        }

        // Warm auth and permission caches before measuring.
        $this->actingAs($this->admin)->getJson('/api/v1/admin/devices?per_page=5')->assertSuccessful();

        $queries = function (int $perPage) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)
                ->getJson('/api/v1/admin/devices?per_page='.$perPage)
                ->assertSuccessful();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $one = $queries(1);
        $five = $queries(5);

        // Five distinct owners. A per-user lazy load would add four queries here.
        expect($five - $one)->toBeLessThanOrEqual(1);
    });
});
