<?php

use App\Enums\DeviceStatus;
use App\Enums\IotModuleStatus;
use App\Models\AlertSubscription;
use App\Models\Device;
use App\Models\DeviceLocation;
use App\Models\IotModule;
use App\Models\TheftReport;
use App\Models\User;
use App\Models\Vacancy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['app.local_timezone' => 'Africa/Blantyre']);
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('customer');
    $this->device = Device::factory()->create(['owner_id' => $this->owner->id]);
});

it('stores offset-qualified IoT instants in UTC', function (string $input) {
    $token = 'timezone-test-token';
    $module = IotModule::factory()->create([
        'api_token' => Hash::make($token), 'status' => IotModuleStatus::Active,
    ]);
    $this->device->update(['iot_module_id' => $module->id, 'status' => DeviceStatus::ACTIVE]);
    $this->postJson('/api/v1/iot/location', [
        'latitude' => -13.96, 'longitude' => 33.77, 'recorded_at' => $input,
    ], ['Authorization' => 'Bearer '.$module->module_id.':'.$token])
        ->assertCreated()->assertJsonPath('data.recorded_at', '2026-01-15T12:00:00+02:00');
    expect(DB::table('device_locations')->where('device_id', $this->device->id)->value('recorded_at'))
        ->toBe('2026-01-15 10:00:00');
})->with(['2026-01-15T12:00:00+02:00', '2026-01-15T10:00:00Z', '2026-01-15T05:00:00-05:00', '2026-01-15 10:00:00']);

it('normalizes IoT batches before choosing the latest location', function () {
    $token = 'timezone-test-token';
    $module = IotModule::factory()->create([
        'api_token' => Hash::make($token), 'status' => IotModuleStatus::Active,
    ]);
    $this->device->update(['iot_module_id' => $module->id, 'status' => DeviceStatus::ACTIVE]);
    $dates = ['2026-01-15T12:00:00+02:00', '2026-01-15T09:30:00Z', '2026-01-15T06:15:00-05:00'];
    $this->postJson('/api/v1/iot/locations/batch', [
        'locations' => array_map(fn ($date) => ['latitude' => -13.96, 'longitude' => 33.77, 'recorded_at' => $date], $dates),
    ], ['Authorization' => 'Bearer '.$module->module_id.':'.$token])->assertCreated();
    expect(DB::table('device_locations')->orderBy('id')->pluck('recorded_at')->all())
        ->toBe(['2026-01-15 10:00:00', '2026-01-15 09:30:00', '2026-01-15 11:15:00'])
        ->and($this->device->fresh()->lastLocation->recorded_at->toDateTimeString())->toBe('2026-01-15 11:15:00');
});

it('normalizes instant filters across every tracking endpoint', function (string $from, string $to) {
    foreach (['09:59:59', '10:00:00', '10:15:00', '10:30:00', '10:30:01'] as $time) {
        DeviceLocation::factory()->for($this->device)->create(['recorded_at' => '2026-01-15 '.$time]);
    }
    $query = http_build_query(['from' => $from, 'to' => $to]);
    $base = '/api/v1/devices/'.$this->device->id.'/locations';
    $this->actingAs($this->owner, 'sanctum')->getJson($base.'?'.$query)
        ->assertSuccessful()->assertJsonCount(3, 'data');
    $this->getJson($base.'/statistics?'.$query)->assertSuccessful()
        ->assertJsonPath('data.total_locations', 3)
        ->assertJsonPath('data.first_location_at', '2026-01-15T12:00:00+02:00');
    $this->getJson($base.'/heatmap?'.$query)->assertSuccessful()->assertJsonPath('data.total_points', 3);
    $this->getJson($base.'/track?'.$query)->assertSuccessful()->assertJsonPath('data.total_points', 3);
})->with([
    ['2026-01-15T12:00:00+02:00', '2026-01-15T12:30:00+02:00'],
    ['2026-01-15T10:00:00Z', '2026-01-15T10:30:00Z'],
    ['2026-01-15T05:00:00-05:00', '2026-01-15T05:30:00-05:00'],
    ['2026-01-15 10:00:00', '2026-01-15 10:30:00'],
]);

it('validates tracking filters and ignores empty optional bounds', function () {
    DeviceLocation::factory()->for($this->device)->create();
    $base = '/api/v1/devices/'.$this->device->id.'/locations';
    foreach (['', '/statistics', '/heatmap', '/track'] as $endpoint) {
        $this->actingAs($this->owner, 'sanctum')->getJson($base.$endpoint.'?from=not-a-date&to=2026-01-15')
            ->assertUnprocessable()->assertJsonValidationErrors('from');
    }
    $this->getJson($base.'?from=&to=')->assertSuccessful()->assertJsonCount(1, 'data');
});

it('keeps community date inputs in UTC through create and update', function (string $input, string $utc) {
    $this->travelTo(Carbon::parse('2026-01-14 22:30:00', 'UTC'));
    $report = TheftReport::factory()->create(['device_id' => $this->device->id, 'reported_by' => $this->owner->id]);
    $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/community/alerts', [
        'theft_report_id' => $report->id, 'broadcast_latitude' => -13.96, 'broadcast_longitude' => 33.77,
        'bounty_amount' => 100, 'expires_at' => '2026-01-16T00:00:00+02:00',
    ])->assertSuccessful()->assertJsonPath('data.expires_at', '2026-01-16T00:00:00+02:00');
    $id = $response->json('data.id');
    expect(DB::table('community_alerts')->where('id', $id)->value('expires_at'))->toBe('2026-01-15 22:00:00');
    $this->putJson('/api/v1/community/alerts/'.$id, ['expires_at' => '2026-01-16T06:00:00-05:00'])
        ->assertSuccessful()->assertJsonPath('data.expires_at', '2026-01-16T13:00:00+02:00');
    expect(DB::table('community_alerts')->where('id', $id)->value('expires_at'))->toBe('2026-01-16 11:00:00');
    $this->putJson('/api/v1/community/alerts/'.$id, ['expires_at' => '2026-01-17'])
        ->assertSuccessful()->assertJsonPath('data.expires_at', '2026-01-17T00:00:00+02:00');
    $this->putJson('/api/v1/community/alerts/'.$id, ['bounty_amount' => 200])->assertSuccessful();
    expect(DB::table('community_alerts')->where('id', $id)->value('expires_at'))->toBe('2026-01-16 22:00:00');
    foreach (['2026-01-15T00:15:00+02:00', '2026-02-31'] as $invalidExpiry) {
        $this->putJson('/api/v1/community/alerts/'.$id, ['expires_at' => $invalidExpiry])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }

    $tip = ['tip_type' => 'sighting', 'description' => 'I saw this device in Blantyre.', 'spotted_at' => $input, 'contact_preference' => 'phone'];
    $this->postJson('/api/v1/community/alerts/'.$id.'/tips', $tip)->assertSuccessful();
    expect(DB::table('community_tips')->value('spotted_at'))->toBe($utc);
    $tip['spotted_at'] = '2026-01-15T00:31:00+02:00';
    $this->postJson('/api/v1/community/alerts/'.$id.'/tips', $tip)
        ->assertUnprocessable()->assertJsonValidationErrors('spotted_at');

    $claim = [
        'claim_type' => 'recovery', 'description' => 'I helped recover the missing device.',
        'evidence_photos' => ['evidence.jpg'], 'recovery_latitude' => -13.96, 'recovery_longitude' => 33.77,
        'recovery_date' => $input, 'payment_method' => 'cash', 'payment_account' => 'Owner',
    ];
    $this->postJson('/api/v1/community/alerts/'.$id.'/claims', $claim)->assertCreated();
    expect(DB::table('bounty_claims')->value('recovery_date'))->toBe($utc);
    $claim['recovery_date'] = '2026-01-15T00:31:00+02:00';
    $this->postJson('/api/v1/community/alerts/'.$id.'/claims', $claim)
        ->assertUnprocessable()->assertJsonValidationErrors('recovery_date');
    $this->putJson('/api/v1/community/alerts/'.$id, ['expires_at' => null])->assertSuccessful();
    expect(DB::table('community_alerts')->where('id', $id)->value('expires_at'))->toBeNull()
        ->and(DB::table('community_alerts')->where('id', $id)->value('created_at'))->toBe('2026-01-14 22:30:00');
})->with([
    ['2026-01-15T00:15:00+02:00', '2026-01-14 22:15:00'],
    ['2026-01-14T22:15:00Z', '2026-01-14 22:15:00'],
    ['2026-01-15', '2026-01-14 22:00:00'],
]);

it('evaluates daytime and overnight quiet hours against Malawi wall time', function (string $utc, string $start, string $end, bool $quiet) {
    $this->travelTo(Carbon::parse($utc, 'UTC'));
    $subscription = new AlertSubscription(['quiet_hours_start' => $start, 'quiet_hours_end' => $end]);
    expect($subscription->isInQuietHours())->toBe($quiet)
        ->and($subscription->quiet_hours_start->format('H:i'))->toBe($start)
        ->and($subscription->quiet_hours_end->format('H:i'))->toBe($end);
})->with([
    ['2026-01-15 10:30:00', '12:00', '13:00', true],
    ['2026-01-15 10:00:00', '12:00', '13:00', true],
    ['2026-01-15 11:01:00', '12:00', '13:00', false],
    ['2026-01-15 20:00:00', '22:00', '06:00', true],
    ['2026-01-15 23:00:00', '22:00', '06:00', true],
    ['2026-01-16 04:01:00', '22:00', '06:00', false],
]);

it('expires public vacancies at Malawi midnight without changing stored dates', function () {
    $this->travelTo(Carbon::parse('2026-01-14 21:59:59', 'UTC'));
    $closing = Vacancy::create([
        'title' => 'Closing', 'employment_type' => 'Full time', 'description' => 'Closing vacancy',
        'closing_date' => '2026-01-14', 'is_published' => true,
    ]);
    Vacancy::create([
        'title' => 'Current', 'employment_type' => 'Full time', 'description' => 'Current vacancy',
        'closing_date' => '2026-01-15', 'is_published' => true,
    ]);
    $this->getJson('/api/v1/public/vacancies')->assertSuccessful()->assertJsonCount(2, 'data');
    $this->travelTo(Carbon::parse('2026-01-14 22:00:00', 'UTC'));
    $this->getJson('/api/v1/public/vacancies')->assertSuccessful()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Current');
    expect($closing->fresh()->closing_date->toDateString())->toBe('2026-01-14')
        ->and(DB::table('vacancies')->where('id', $closing->id)->value('created_at'))->toBe('2026-01-14 21:59:59');
});
