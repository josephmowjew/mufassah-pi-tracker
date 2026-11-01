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

it('displays shift times in the local timezone in the summary notification', function (string $start, string $end, string $localStart, string $localEnd) {
    config(['app.local_timezone' => 'Africa/Blantyre']);
    $staff = User::factory()->create(['branch_id' => $this->branch->id]);

    $shift = Shift::create([
        'user_id' => $staff->id,
        'branch_id' => $this->branch->id,
        'started_at' => $start,
        'ended_at' => $end,
        'status' => 'completed',
    ]);

    $mail = (new \App\Notifications\ShiftEndSummaryNotification($shift))->toMail($staff);
    $body = implode("\n", $mail->introLines);

    expect($body)
        ->toContain('- Started: '.$localStart)
        ->toContain('- Ended: '.$localEnd)
        ->and($shift->fresh()->started_at->toDateTimeString())->toBe($start)
        ->and($shift->fresh()->ended_at->toDateTimeString())->toBe($end);
})->with([
    'daytime' => ['2026-01-15 10:00:00', '2026-01-15 18:00:00', '2026-01-15 12:00', '2026-01-15 20:00'],
    'local midnight' => ['2026-01-15 21:30:00', '2026-01-15 22:30:00', '2026-01-15 23:30', '2026-01-16 00:30'],
]);

it('keeps the recorded start time when a shift is completed', function () {
    $staff = User::factory()->create(['branch_id' => $this->branch->id]);

    $startedAt = now()->subHours(8)->startOfSecond();

    $shift = Shift::create([
        'user_id' => $staff->id,
        'branch_id' => $this->branch->id,
        'started_at' => $startedAt,
        'status' => 'active',
    ]);

    $shift->complete(['closing_cash' => 500]);

    $shift->refresh();

    // Completing a shift is an UPDATE. Before the ON UPDATE timestamp clause was
    // removed, MariaDB rewrote started_at to now() during that UPDATE, so started_at
    // and ended_at landed on the same second and every shift reported 0 seconds.
    expect($shift->started_at->toDateTimeString())
        ->toBe($startedAt->toDateTimeString())
        ->and($shift->ended_at)->not->toBeNull()
        ->and($shift->ended_at->greaterThan($shift->started_at))->toBeTrue()
        ->and($shift->duration)->toBeGreaterThan(0);
});
