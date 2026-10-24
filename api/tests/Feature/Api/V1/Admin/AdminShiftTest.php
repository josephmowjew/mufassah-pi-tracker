<?php

use App\Models\Branch;
use App\Models\Shift;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->branch = Branch::create(['name' => 'Shift Branch', 'code' => 'SHF', 'is_active' => true]);
    $this->otherBranch = Branch::create(['name' => 'Other Shift Branch', 'code' => 'OSH', 'is_active' => true]);
    $this->storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->storeAdmin->assignRole('store_admin');
});

it('limits store admins to shifts in their branch', function () {
    $ownStaff = User::factory()->create(['branch_id' => $this->branch->id]);
    $otherStaff = User::factory()->create(['branch_id' => $this->otherBranch->id]);
    $ownShift = Shift::create([
        'user_id' => $ownStaff->id,
        'branch_id' => $this->branch->id,
        'started_at' => now(),
        'status' => 'active',
    ]);
    $otherShift = Shift::create([
        'user_id' => $otherStaff->id,
        'branch_id' => $this->otherBranch->id,
        'started_at' => now(),
        'status' => 'active',
    ]);

    $this->actingAs($this->storeAdmin)
        ->getJson('/api/v1/admin/shifts')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $ownShift->id);

    $this->actingAs($this->storeAdmin)
        ->getJson("/api/v1/admin/shifts/{$otherShift->id}")
        ->assertForbidden();
});

it('keeps global shift access available to super admins', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $staff = User::factory()->create(['branch_id' => $this->otherBranch->id]);
    Shift::create([
        'user_id' => $staff->id,
        'branch_id' => $this->otherBranch->id,
        'started_at' => now(),
        'status' => 'active',
    ]);

    $this->actingAs($superAdmin)
        ->getJson('/api/v1/admin/shifts')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1);
});

it('renders the real staff name in the shift summary notification', function () {
    $staff = User::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Chisomo Banda',
    ]);

    $shift = Shift::create([
        'user_id' => $staff->id,
        'branch_id' => $this->branch->id,
        'started_at' => now()->subHours(8),
        'ended_at' => now(),
        'status' => 'completed',
    ]);

    $mail = (new \App\Notifications\ShiftEndSummaryNotification($shift))->toMail($staff);
    $body = implode("\n", $mail->introLines);

    expect($body)
        ->toContain('- Staff: Chisomo Banda')
        ->not->toContain('Unknown Staff');
});
