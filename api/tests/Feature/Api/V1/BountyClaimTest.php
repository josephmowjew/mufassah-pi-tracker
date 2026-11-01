<?php

use App\Enums\BountyStatus;
use App\Enums\CommunityAlertStatus;
use App\Models\BountyClaim;
use App\Models\CommunityAlert;
use App\Models\Device;
use App\Models\TheftReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['app.local_timezone' => 'Africa/Blantyre']);
    $this->travelTo(Carbon::parse('2026-01-14 22:30:00', 'UTC'));
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('customer');
    $this->claimant = User::factory()->create();
    $this->claimant->assignRole('customer');
    $device = Device::factory()->create(['owner_id' => $this->owner->id]);
    $report = TheftReport::factory()->create(['device_id' => $device->id, 'reported_by' => $this->owner->id]);
    $this->alert = CommunityAlert::create([
        'theft_report_id' => $report->id, 'created_by' => $this->owner->id,
        'status' => CommunityAlertStatus::Active,
        'broadcast_latitude' => -13.96, 'broadcast_longitude' => 33.77,
        'bounty_amount' => 100, 'bounty_status' => BountyStatus::Offered,
    ]);
    $this->payload = [
        'claim_type' => 'recovery', 'description' => 'I helped recover the missing device.',
        'evidence_photos' => ['evidence.jpg'], 'recovery_latitude' => -13.96, 'recovery_longitude' => 33.77,
        'recovery_date' => '2026-01-15T00:15:00+02:00',
        'payment_method' => 'cash', 'payment_account' => 'Claimant',
    ];
    $this->endpoint = '/api/v1/community/alerts/'.$this->alert->id.'/claims';
});

it('saves each supported bounty claim type end to end with UTC timestamps', function (string $type, string $input, string $stored, string $displayed) {
    $payload = array_replace($this->payload, ['claim_type' => $type, 'recovery_date' => $input]);
    $response = $this->actingAs($this->claimant, 'sanctum')->postJson($this->endpoint, $payload)
        ->assertCreated()
        ->assertJsonPath('data.claim_type', $type)
        ->assertJsonPath('data.recovery_date', $displayed)
        ->assertJsonPath('data.created_at', '2026-01-15T00:30:00+02:00')
        ->assertJsonPath('data.status.value', 'pending');
    $id = $response->json('data.id');
    $this->assertDatabaseHas('bounty_claims', [
        'id' => $id, 'claim_type' => $type, 'recovery_date' => $stored,
        'created_at' => '2026-01-14 22:30:00', 'updated_at' => '2026-01-14 22:30:00',
        'claimant_id' => $this->claimant->id, 'community_alert_id' => $this->alert->id,
    ]);
    expect(BountyClaim::findOrFail($id)->recovery_date->timezoneName)->toBe('UTC');
    $this->actingAs($this->owner, 'sanctum')->getJson($this->endpoint)
        ->assertSuccessful()->assertJsonPath('data.0.claim_type', $type)
        ->assertJsonPath('data.0.recovery_date', $displayed);
})->with(['recovery', 'tip_leading_to_recovery', 'partial_recovery'])->with([
    'Malawi offset' => ['2026-01-15T00:15:00+02:00', '2026-01-14 22:15:00', '2026-01-15T00:15:00+02:00'],
    'UTC offset' => ['2026-01-14T22:15:00Z', '2026-01-14 22:15:00', '2026-01-15T00:15:00+02:00'],
    'negative offset' => ['2026-01-14T17:15:00-05:00', '2026-01-14 22:15:00', '2026-01-15T00:15:00+02:00'],
    'naive UTC datetime' => ['2026-01-14 22:15:00', '2026-01-14 22:15:00', '2026-01-15T00:15:00+02:00'],
    'Malawi calendar date' => ['2026-01-15', '2026-01-14 22:00:00', '2026-01-15T00:00:00+02:00'],
]);

it('rejects invalid claim types and dates without saving a claim', function (array $changes, string $field) {
    $this->actingAs($this->claimant, 'sanctum')
        ->postJson($this->endpoint, array_replace($this->payload, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('bounty_claims', 0);
})->with([
    [['claim_type' => 'unsupported'], 'claim_type'],
    [['claim_type' => ''], 'claim_type'],
    [['recovery_date' => '2026-01-15T00:31:00+02:00'], 'recovery_date'],
    [['recovery_date' => '2026-01-16'], 'recovery_date'],
    [['recovery_date' => '2026-02-31'], 'recovery_date'],
    [['recovery_date' => 'not-a-date'], 'recovery_date'],
]);

it('keeps the duplicate pending claim guard after successful submissions', function () {
    $this->actingAs($this->claimant, 'sanctum')->postJson($this->endpoint, $this->payload)->assertCreated();
    $this->postJson($this->endpoint, array_replace($this->payload, ['claim_type' => 'partial_recovery']))
        ->assertUnprocessable()->assertJsonPath('message', 'You already have a pending or approved claim for this alert.');
    $this->assertDatabaseCount('bounty_claims', 1);
});

it('expands claim types on an existing database without rewriting legacy claims', function () {
    $migration = require database_path('migrations/2026_11_01_000001_expand_bounty_claim_types.php');
    $migration->down();
    foreach (['recovered_device', 'led_to_recovery', 'key_information'] as $type) {
        BountyClaim::create(array_replace($this->payload, [
            'community_alert_id' => $this->alert->id, 'claimant_id' => $this->claimant->id,
            'claim_type' => $type, 'status' => 'rejected', 'recovery_date' => '2026-01-14 22:15:00',
        ]));
    }
    $before = DB::table('bounty_claims')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $indexes = collect(Schema::getIndexes('bounty_claims'))->keyBy('name')->sortKeys()->all();
    $foreignKeys = collect(Schema::getForeignKeys('bounty_claims'))->sortBy(fn ($key) => implode(',', $key['columns']))->values()->all();
    $migration->up();
    expect(collect(Schema::getIndexes('bounty_claims'))->keyBy('name')->sortKeys()->all())->toBe($indexes)
        ->and(collect(Schema::getForeignKeys('bounty_claims'))->sortBy(fn ($key) => implode(',', $key['columns']))->values()->all())->toBe($foreignKeys);
    expect(DB::table('bounty_claims')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
    $this->actingAs($this->claimant, 'sanctum')->postJson($this->endpoint, $this->payload)->assertCreated();
    expect(DB::table('bounty_claims')->whereIn('claim_type', ['recovered_device', 'led_to_recovery', 'key_information'])->count())->toBe(3);
});

it('refuses a schema rollback that would invalidate current claim types', function () {
    $this->actingAs($this->claimant, 'sanctum')->postJson($this->endpoint, $this->payload)->assertCreated();
    $migration = require database_path('migrations/2026_11_01_000001_expand_bounty_claim_types.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'claim types');
    $this->assertDatabaseHas('bounty_claims', ['claim_type' => 'recovery', 'recovery_date' => '2026-01-14 22:15:00']);
});

it('retains claim status constraints while widening claim types', function () {
    $claim = BountyClaim::create(array_replace($this->payload, [
        'community_alert_id' => $this->alert->id, 'claimant_id' => $this->claimant->id,
        'status' => 'pending', 'recovery_date' => '2026-01-14 22:15:00',
    ]));
    $invalid = $claim->getAttributes();
    unset($invalid['id']);
    $invalid['status'] = 'unsupported';
    expect(fn () => DB::table('bounty_claims')->insert($invalid))->toThrow(QueryException::class);
});
