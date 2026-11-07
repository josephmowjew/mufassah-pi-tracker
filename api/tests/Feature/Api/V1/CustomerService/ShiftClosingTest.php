<?php

use App\Models\Branch;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['app.local_timezone' => 'Africa/Blantyre']);
    Role::firstOrCreate(['name' => 'customer_service', 'guard_name' => 'api']);
    $branch = Branch::create(['name' => 'Closing Branch', 'code' => 'CLS', 'is_active' => true]);
    $this->agent = User::factory()->create(['branch_id' => $branch->id]);
    $this->agent->assignRole('customer_service');
});

it('exposes the closing deadline at 5:30 pm Malawi time and closes a shift and its break at 6 pm', function () {
    $shift = Shift::create([
        'user_id' => $this->agent->id,
        'branch_id' => $this->agent->branch_id,
        'started_at' => '2026-11-07 08:00:00',
        'status' => 'active',
    ]);
    $break = $shift->breaks()->create(['type' => 'tea', 'started_at' => '2026-11-07 15:45:00']);

    $this->travelTo(Carbon::parse('2026-11-07 15:30:00', 'UTC'));
    $this->actingAs($this->agent)->getJson('/api/v1/customer-service/shifts/current')
        ->assertOk()->assertJsonPath('data.automatic_end_at', '2026-11-07T18:00:00+02:00');

    $this->travelTo(Carbon::parse('2026-11-07 15:59:59', 'UTC'));
    $this->artisan('shifts:close-expired')->assertSuccessful();
    expect($shift->fresh()->status)->toBe('active');

    $this->travelTo(Carbon::parse('2026-11-07 16:00:00', 'UTC'));
    $this->artisan('shifts:close-expired')->assertSuccessful();
    $this->artisan('shifts:close-expired')->assertSuccessful();

    expect($shift->fresh()->status)->toBe('completed')
        ->and($shift->fresh()->ended_at->toDateTimeString())->toBe('2026-11-07 16:00:00')
        ->and($break->fresh()->ended_at->toDateTimeString())->toBe('2026-11-07 16:00:00');
});

it('closes a forgotten shift at its ten-hour limit even if the scheduler runs the next day', function () {
    $shift = Shift::create([
        'user_id' => $this->agent->id,
        'branch_id' => $this->agent->branch_id,
        'started_at' => '2026-11-07 04:00:00',
        'status' => 'active',
    ]);
    $shift->breaks()->create([
        'type' => 'lunch',
        'started_at' => '2026-11-07 13:45:00',
        'ended_at' => '2026-11-07 14:15:00',
    ]);
    $lateBreak = $shift->breaks()->create(['type' => 'tea', 'started_at' => '2026-11-07 15:00:00']);

    $this->travelTo(Carbon::parse('2026-11-08 07:00:00', 'UTC'));
    $this->artisan('shifts:close-expired')->assertSuccessful();

    $shift->refresh();
    expect($shift->ended_at->toDateTimeString())->toBe('2026-11-07 14:00:00')
        ->and($shift->duration)->toBe(600.0)
        ->and($shift->break_minutes)->toBe(15.0)
        ->and($lateBreak->fresh()->duration_minutes)->toBe(0.0);
});

it('enforces closing on staff requests while the scheduler is between runs', function () {
    $this->travelTo(Carbon::parse('2026-11-07 07:00:00', 'UTC'));
    $id = $this->actingAs($this->agent)->postJson('/api/v1/customer-service/shifts/start')
        ->assertCreated()->json('data.id');

    $this->travelTo(Carbon::parse('2026-11-07 16:00:00', 'UTC'));
    $this->postJson('/api/v1/customer-service/shifts/breaks/start')->assertNotFound();
    $this->getJson('/api/v1/customer-service/shifts/current')->assertOk()->assertJsonPath('data', null);
    $this->postJson('/api/v1/customer-service/shifts/start')->assertBadRequest();

    expect(Shift::findOrFail($id)->status)->toBe('completed');
});
