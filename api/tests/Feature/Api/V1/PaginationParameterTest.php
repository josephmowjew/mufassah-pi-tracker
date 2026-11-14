<?php

use App\Models\Branch;
use App\Models\User;

/**
 * Every list endpoint used to pass `per_page` straight to `paginate()`, so
 * `?per_page=1000000` returned the whole table and `?per_page=-5` was a 500.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->branch = Branch::create([
        'name' => 'Blantyre Branch',
        'code' => 'BT',
        'city' => 'Blantyre',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->agent = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->agent->assignRole('customer_service');
});

dataset('paginated endpoints', [
    '/api/v1/admin/users',
    '/api/v1/admin/devices',
    '/api/v1/admin/inventory',
    '/api/v1/admin/sales',
    '/api/v1/admin/shifts',
    '/api/v1/admin/families',
    '/api/v1/admin/branches',
    '/api/v1/admin/iot-modules',
    '/api/v1/admin/vacancies',
    '/api/v1/admin/invoices',
]);

it('caps the page size on admin lists', function (string $endpoint) {
    $this->actingAs($this->admin)
        ->getJson($endpoint.'?per_page=1000000')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
})->with('paginated endpoints');

it('caps the page size on customer service lists', function (string $endpoint) {
    $this->actingAs($this->agent)
        ->getJson($endpoint.'?per_page=1000000')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
})->with([
    '/api/v1/customer-service/customers',
    '/api/v1/customer-service/devices',
    '/api/v1/customer-service/sales',
    '/api/v1/customer-service/shifts/history',
]);

it('rejects a page size below one instead of erroring', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?per_page=-5')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('rejects a non numeric page size', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?per_page=all')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('accepts the largest page size the app itself asks for', function () {
    // 100 is what the busiest screen (admin tracking) requests.
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?per_page=100')
        ->assertSuccessful()
        ->assertJsonPath('meta.per_page', 100);
});

it('uses the endpoint default when no page size is requested', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users')
        ->assertSuccessful()
        ->assertJsonPath('meta.per_page', 15);
});

it('honours an in range page size', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?per_page=25')
        ->assertSuccessful()
        ->assertJsonPath('meta.per_page', 25);
});
