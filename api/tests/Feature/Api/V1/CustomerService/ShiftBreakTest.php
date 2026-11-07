<?php

use App\Enums\ShiftBreakType;
use App\Http\Resources\ShiftResource;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\ShiftBreak;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'UTC'));

    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'customer_service', 'guard_name' => 'api']);

    $this->branch = Branch::create([
        'name' => 'Break Branch',
        'code' => 'BRK',
        'is_active' => true,
    ]);

    $this->agent = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->agent->assignRole('customer_service');

    $this->shift = Shift::create([
        'user_id' => $this->agent->id,
        'branch_id' => $this->branch->id,
        'started_at' => now()->subHours(2),
        'status' => 'active',
    ]);
});

it('records a break without ending the shift', function () {
    $response = $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/start', [
            'type' => 'lunch',
            'notes' => 'Counter covered by Grace',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.on_break', true)
        ->assertJsonPath('data.break_count', 1)
        ->assertJsonPath('data.breaks.0.type', 'lunch')
        ->assertJsonPath('data.breaks.0.is_open', true);

    // The whole point: the shift is still active, so `activeShift()` still
    // resolves and the employee is not locked out of ending their day.
    $this->shift->refresh();
    expect($this->shift->status)->toBe('active')
        ->and($this->shift->ended_at)->toBeNull()
        ->and($this->shift->on_break)->toBeTrue();
});

it('stops a second break starting while one is running', function () {
    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/start', ['type' => 'tea'])
        ->assertCreated();

    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/start', ['type' => 'lunch'])
        ->assertStatus(409);

    expect(ShiftBreak::where('shift_id', $this->shift->id)->count())->toBe(1);
});

it('refuses to end a break that was never started', function () {
    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/end')
        ->assertStatus(409);
});

it('refuses to start a break without an active shift', function () {
    $this->shift->update(['status' => 'completed', 'ended_at' => now()]);

    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/start', ['type' => 'tea'])
        ->assertStatus(404);
});

it('closes the break when the employee returns', function () {
    $resumeAt = now();
    $this->travelTo($resumeAt->copy()->subMinutes(30));

    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/start', ['type' => 'tea'])
        ->assertCreated();

    $this->travelTo($resumeAt);

    $response = $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/end');

    $response->assertSuccessful()
        ->assertJsonPath('data.on_break', false)
        ->assertJsonPath('data.break_count', 1);

    $break = ShiftBreak::where('shift_id', $this->shift->id)->first();

    expect($break->ended_at)->not->toBeNull()
        ->and($break->duration_minutes)->toBe(30.0);
});

it('subtracts break time from worked time', function () {
    $shift = Shift::create([
        'user_id' => $this->agent->id,
        'branch_id' => $this->branch->id,
        'started_at' => now()->subHours(8),
        'status' => 'active',
    ]);

    $shift->breaks()->create([
        'type' => ShiftBreakType::LUNCH,
        'started_at' => now()->subHours(5),
        'ended_at' => now()->subHours(5)->addMinutes(45),
    ]);

    $shift->breaks()->create([
        'type' => ShiftBreakType::TEA,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(2)->addMinutes(15),
    ]);

    $shift->complete();

    expect($shift->duration)->toBe(480.0)
        ->and($shift->break_minutes)->toBe(60.0)
        ->and($shift->break_count)->toBe(2)
        ->and($shift->worked_minutes)->toBe(420.0);
});

it('closes a running break when the shift ends', function () {
    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/start', ['type' => 'lunch'])
        ->assertCreated();

    $response = $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/end', ['closing_cash' => 500]);

    $response->assertSuccessful()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.on_break', false);

    $shift = $this->shift->fresh();
    $break = ShiftBreak::where('shift_id', $shift->id)->first();

    // Without this, ending a shift mid-break would leave a break with no end
    // and every report would read it as an unbounded break.
    expect($break->ended_at)->not->toBeNull()
        ->and($break->ended_at->equalTo($shift->ended_at))->toBeTrue();
});

it('closes a running break when the shift is cancelled', function () {
    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/start', ['type' => 'other'])
        ->assertCreated();

    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/cancel', ['notes' => 'Till count mismatch'])
        ->assertSuccessful();

    expect(ShiftBreak::where('shift_id', $this->shift->id)->whereNull('ended_at')->exists())->toBeFalse();
});

it('reports the running break on the current shift endpoint', function () {
    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/start', ['type' => 'prayer'])
        ->assertCreated();

    $this->actingAs($this->agent)
        ->getJson('/api/v1/customer-service/shifts/current')
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.on_break', true)
        ->assertJsonPath('data.breaks.0.type', 'prayer')
        ->assertJsonPath('data.breaks.0.type_label', 'Prayer');
});

it('accepts every declared break type', function () {
    // Guards the enum against the column: if a case is added to ShiftBreakType
    // but not to the migration's enum list, the insert fails here rather than
    // in production.
    foreach (ShiftBreakType::cases() as $case) {
        $this->actingAs($this->agent)
            ->postJson('/api/v1/customer-service/shifts/breaks/start', ['type' => $case->value])
            ->assertCreated();

        $latest = ShiftBreak::where('shift_id', $this->shift->id)->latest('id')->first();

        expect($latest->type)->toBe($case);

        $this->actingAs($this->agent)
            ->postJson('/api/v1/customer-service/shifts/breaks/end')
            ->assertSuccessful();
    }

    expect(ShiftBreak::where('shift_id', $this->shift->id)->count())
        ->toBe(count(ShiftBreakType::cases()));
});

it('serialises break totals for a shift whose breaks were not eager loaded', function () {
    $this->shift->breaks()->create([
        'type' => ShiftBreakType::TEA,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(5),
    ]);

    // A fresh instance with no eager-loaded relation, as a single-record
    // endpoint that forgot to load it would be. The resource must still render
    // correct totals rather than throwing or emitting a broken collection.
    $json = (new ShiftResource(Shift::find($this->shift->id)))->resolve();

    expect($json['break_count'])->toBe(1)
        ->and($json['break_minutes'])->toBe(15.0)
        ->and($json['on_break'])->toBeFalse()
        ->and($json['breaks'])->toHaveCount(1);
});

it('keeps break types inside the allowed set', function () {
    $this->actingAs($this->agent)
        ->postJson('/api/v1/customer-service/shifts/breaks/start', ['type' => 'smoke'])
        ->assertStatus(422);
});
