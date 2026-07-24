<?php

use App\Models\User;
use App\Models\Vacancy;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);
});

it('lets both admin roles manage vacancies', function () {
    $admin = User::factory()->create();
    $admin->assignRole('store_admin');

    $response = $this->actingAs($admin)->postJson('/api/v1/admin/vacancies', [
        'title' => 'Home Theatre Sales Advisor',
        'location' => 'Blantyre',
        'employment_type' => 'Full-time',
        'description' => 'Help customers choose televisions and home-entertainment systems.',
        'application_email' => 'careers@example.test',
        'is_published' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Home Theatre Sales Advisor')
        ->assertJsonPath('data.is_published', true);

    $this->assertDatabaseHas('vacancies', [
        'title' => 'Home Theatre Sales Advisor',
        'is_published' => true,
    ]);
});

it('only exposes published, unexpired vacancies to the public', function () {
    $visible = Vacancy::query()->create([
        'title' => 'Audio Specialist',
        'employment_type' => 'Full-time',
        'description' => 'Advise customers on sound systems.',
        'is_published' => true,
        'published_at' => now(),
    ]);

    Vacancy::query()->create([
        'title' => 'Private Draft',
        'employment_type' => 'Full-time',
        'description' => 'Not ready to publish.',
        'is_published' => false,
    ]);

    Vacancy::query()->create([
        'title' => 'Expired Role',
        'employment_type' => 'Part-time',
        'description' => 'This closing date has passed.',
        'is_published' => true,
        'published_at' => now()->subWeek(),
        'closing_date' => today()->subDay(),
    ]);

    $this->getJson('/api/v1/public/vacancies')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonMissingPath('data.0.is_published');
});
