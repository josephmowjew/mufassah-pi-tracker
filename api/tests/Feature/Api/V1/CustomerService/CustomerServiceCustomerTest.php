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
