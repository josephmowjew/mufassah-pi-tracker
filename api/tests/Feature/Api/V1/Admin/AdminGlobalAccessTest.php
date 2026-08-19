<?php

use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->storeAdmin = User::factory()->create();
    $this->storeAdmin->assignRole('store_admin');
});

it('reserves global administration surfaces for super admins', function (string $endpoint) {
    $this->actingAs($this->storeAdmin)
        ->getJson($endpoint)
        ->assertForbidden();
})->with([
    '/api/v1/admin/communications',
    '/api/v1/admin/vacancies',
    '/api/v1/admin/families',
    '/api/v1/admin/community/alerts',
]);
