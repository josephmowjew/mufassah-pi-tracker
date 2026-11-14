<?php

use App\Models\Branch;
use App\Models\User;

/**
 * List endpoints must not pass an arbitrary `sort` to the query builder.
 *
 * Laravel quotes the identifier rather than interpolating it, so this was never
 * an injection hole, but an unknown column reached MySQL and answered with a
 * 500. A rejected sort must now be a normal 422.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->branch = Branch::create([
        'name' => 'Lilongwe Branch',
        'code' => 'LLW',
        'city' => 'Lilongwe',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->agent = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->agent->assignRole('customer_service');
});

dataset('admin sortable endpoints', [
    '/api/v1/admin/users',
    '/api/v1/admin/devices',
    '/api/v1/admin/inventory',
    '/api/v1/admin/sales',
    '/api/v1/admin/shifts',
    '/api/v1/admin/families',
    '/api/v1/admin/iot-modules',
]);

it('rejects an unknown sort column on admin lists', function (string $endpoint) {
    $this->actingAs($this->admin)
        ->getJson($endpoint.'?sort=password')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sort');
})->with('admin sortable endpoints');

it('rejects an unknown sort column on customer service lists', function (string $endpoint) {
    $this->actingAs($this->agent)
        ->getJson($endpoint.'?sort=password')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sort');
})->with([
    '/api/v1/customer-service/customers',
    '/api/v1/customer-service/devices',
    '/api/v1/customer-service/sales',
    '/api/v1/customer-service/shifts/history',
]);

it('rejects a sideways sort direction', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?sort=name&direction=sideways')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('direction');
});

it('still sorts by every whitelisted column', function (string $column) {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?sort='.$column.'&direction=asc');

    // The route returns a resource collection, so a successful sort is 200.
    $response->assertSuccessful();
})->with(['name', 'email', 'phone_number', 'branch_id', 'created_at', 'updated_at']);

it('keeps the default ordering when no sort is requested', function () {
    User::factory()->create(['name' => 'Zulu Customer'])->assignRole('customer');
    User::factory()->create(['name' => 'Alpha Customer'])->assignRole('customer');

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users')
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?sort=name&direction=asc')
        ->assertSuccessful()
        ->assertJsonPath('data.0.name', 'Alpha Customer');
});
