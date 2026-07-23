<?php

use App\Models\Branch;
use function Pest\Laravel\getJson;

describe('Public branches', function () {
    it('lists only active branch contact details without authentication', function () {
        $active = Branch::query()->create([
            'name' => 'Blantyre',
            'code' => 'BT-01',
            'address' => 'Victoria Avenue',
            'city' => 'Blantyre',
            'phone' => '+265 999 000 111',
            'email' => 'blantyre@example.com',
            'is_active' => true,
        ]);

        Branch::query()->create([
            'name' => 'Closed branch',
            'code' => 'CL-01',
            'city' => 'Lilongwe',
            'is_active' => false,
        ]);

        getJson(apiUrl('public/branches'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonMissingPath('data.0.code')
            ->assertJsonMissingPath('data.0.manager_id');
    });
});
