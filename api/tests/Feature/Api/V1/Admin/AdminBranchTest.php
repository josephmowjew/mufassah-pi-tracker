<?php

use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

describe('Admin Branch Management', function () {
    it('creates and updates branch details', function () {
        $manager = User::factory()->create();
        $manager->assignRole('super_admin');

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/branches', [
            'name' => 'Mzuzu Branch',
            'code' => 'MZU',
            'city' => 'Mzuzu',
            'phone' => '+265999111222',
            'email' => 'mzuzu@example.test',
            'manager_id' => $manager->id,
            'is_active' => true,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Mzuzu Branch')
            ->assertJsonPath('data.code', 'MZU');

        $branchId = $response->json('data.id');

        $this->actingAs($this->admin)->putJson("/api/v1/admin/branches/{$branchId}", [
            'name' => 'Mzuzu Main Branch',
            'code' => 'MZU',
            'is_active' => false,
        ])->assertSuccessful()
            ->assertJsonPath('data.name', 'Mzuzu Main Branch')
            ->assertJsonPath('data.is_active', false);
    });

    it('does not delete branches with linked stock records', function () {
        $branch = Branch::create([
            'name' => 'Linked Branch',
            'code' => 'LNK',
            'city' => 'Blantyre',
            'is_active' => true,
        ]);

        Device::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/branches/{$branch->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This branch has linked records. Deactivate it instead of deleting it.');
    });

    it('assigns a store admin manager to the managed branch', function () {
        $manager = User::factory()->create();
        $manager->assignRole('store_admin');

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/branches', [
            'name' => 'Lilongwe Branch',
            'code' => 'LLW',
            'manager_id' => $manager->id,
            'is_active' => true,
        ]);

        $response->assertSuccessful();
        expect($manager->fresh()->branch_id)->toBe($response->json('data.id'));
    });

    it('only lets store admins view their branch and reserves branch management for super admins', function () {
        $branch = Branch::create(['name' => 'Store Branch', 'code' => 'STR', 'is_active' => true]);
        Branch::create(['name' => 'Hidden Branch', 'code' => 'HID', 'is_active' => true]);
        $storeAdmin = User::factory()->create(['branch_id' => $branch->id]);
        $storeAdmin->assignRole('store_admin');

        $this->actingAs($storeAdmin)
            ->getJson('/api/v1/admin/branches?per_page=100')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $branch->id);

        $this->actingAs($storeAdmin)
            ->postJson('/api/v1/admin/branches', [
                'name' => 'Unauthorized Branch',
                'code' => 'UNA',
                'is_active' => true,
            ])
            ->assertForbidden();
    });
});
