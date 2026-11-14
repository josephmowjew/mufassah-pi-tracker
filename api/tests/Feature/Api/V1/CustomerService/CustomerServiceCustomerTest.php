<?php

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'customer_service', 'guard_name' => 'api']);

    $this->branch = Branch::create([
        'name' => 'Service Branch',
        'code' => 'SRV',
        'city' => 'Blantyre',
        'is_active' => true,
    ]);

    $this->agent = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->agent->assignRole('customer_service');
});

it('allows customer service to create customer without email or password', function () {
    $response = $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/customers', [
            'name' => 'Counter Customer',
            'phone_number' => '+265888000222',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Counter Customer')
        ->assertJsonPath('data.email', null);

    $customer = User::where('phone_number', '+265888000222')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->email)->toBeNull()
        ->and($customer->branch_id)->toBe($this->branch->id)
        ->and($customer->hasRole('customer'))->toBeTrue();
});

it('answers a duplicate customer phone number with a field error, not a 500', function () {
    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/customers', [
            'name' => 'First Customer',
            'phone_number' => '+265888000333',
        ])->assertCreated();

    // users.phone_number is unique in the database, so the second attempt has
    // to fail validation with a readable error rather than a 500.
    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/customers', [
            'name' => 'Second Customer',
            'phone_number' => '+265888000333',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone_number');

    expect(User::where('phone_number', '+265888000333')->count())->toBe(1);
});

/**
 * The desk list is branch scoped and sortable only by columns it advertises.
 */
describe('Customer Listing', function () {
    it('only lists customers from the representative\'s own branch', function () {
        $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTH', 'city' => 'Mzuzu', 'is_active' => true]);

        User::factory()->create(['name' => 'Own Branch Customer', 'branch_id' => $this->branch->id])->assignRole('customer');
        User::factory()->create(['name' => 'Other Branch Customer', 'branch_id' => $otherBranch->id])->assignRole('customer');
        // Staff are never customers, even though they share the branch.
        User::factory()->create(['name' => 'Branch Agent', 'branch_id' => $this->branch->id])->assignRole('customer_service');

        $response = $this->actingAs($this->agent)
            ->getJson('/api/v1/customer-service/customers')
            ->assertSuccessful();

        $names = collect($response->json('data'))->pluck('name');

        expect($names)->toContain('Own Branch Customer')
            ->and($names)->not->toContain('Other Branch Customer')
            ->and($names)->not->toContain('Branch Agent');
    });

    it('finds a customer by phone number', function () {
        User::factory()->create([
            'name' => 'Phone Only Customer',
            'phone_number' => '+265888777111',
            'branch_id' => $this->branch->id,
        ])->assignRole('customer');

        $this->actingAs($this->agent)
            ->getJson('/api/v1/customer-service/customers?search=888777')
            ->assertSuccessful()
            ->assertJsonPath('data.0.name', 'Phone Only Customer');
    });

    it('rejects an unknown sort column instead of failing on the query', function () {
        $this->actingAs($this->agent)
            ->getJson('/api/v1/customer-service/customers?sort=password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    });

    it('refuses to list anything for an account with no branch', function () {
        $unassigned = User::factory()->create(['branch_id' => null]);
        $unassigned->assignRole('customer_service');

        $this->actingAs($unassigned)
            ->getJson('/api/v1/customer-service/customers')
            ->assertForbidden();
    });
});
