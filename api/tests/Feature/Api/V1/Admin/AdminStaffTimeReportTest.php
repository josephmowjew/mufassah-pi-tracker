<?php

use App\Enums\ShiftBreakType;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['customer', 'super_admin', 'store_admin', 'customer_service'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
    }

    $this->lilongwe = Branch::create(['name' => 'Lilongwe', 'code' => 'LLW', 'is_active' => true]);
    $this->blantyre = Branch::create(['name' => 'Blantyre', 'code' => 'BT', 'is_active' => true]);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');

    $this->storeAdmin = User::factory()->create(['branch_id' => $this->lilongwe->id]);
    $this->storeAdmin->assignRole('store_admin');

    /**
     * A completed shift of `$hours`, optionally carrying breaks described as
     * [type, minutes] pairs, all ending at the same instant.
     */
    $this->makeShift = function (
        User $staff,
        Branch $branch,
        float $hours,
        array $breaks = [],
        string $status = 'completed',
    ): Shift {
        $endedAt = now()->startOfSecond();
        $startedAt = $endedAt->copy()->subMinutes((int) round($hours * 60));

        $shift = Shift::create([
            'user_id' => $staff->id,
            'branch_id' => $branch->id,
            'started_at' => $startedAt,
            'ended_at' => $status === 'active' ? null : $endedAt,
            'status' => $status,
        ]);

        $cursor = $startedAt->copy()->addMinutes(30);

        foreach ($breaks as [$type, $minutes]) {
            $shift->breaks()->create([
                'type' => $type,
                'started_at' => $cursor,
                'ended_at' => $cursor->copy()->addMinutes($minutes),
            ]);
            $cursor = $cursor->copy()->addMinutes($minutes + 30);
        }

        return $shift;
    };
});

it('separates scheduled, break and worked time per employee', function () {
    $alice = User::factory()->create(['name' => 'Alice Phiri', 'branch_id' => $this->lilongwe->id]);
    $bob = User::factory()->create(['name' => 'Bob Mwale', 'branch_id' => $this->lilongwe->id]);

    ($this->makeShift)($alice, $this->lilongwe, 8, [
        [ShiftBreakType::LUNCH, 45],
        [ShiftBreakType::TEA, 15],
    ]);
    ($this->makeShift)($bob, $this->lilongwe, 4);

    $response = $this->actingAs($this->storeAdmin)
        ->getJson('/api/v1/admin/reports/staff-time')
        ->assertSuccessful();

    $summary = $response->json('data.summary');

    expect((int) $summary['total_shifts'])->toBe(2)
        ->and((int) $summary['staff_count'])->toBe(2)
        ->and((int) $summary['total_break_count'])->toBe(2)
        ->and((float) $summary['total_scheduled_minutes'])->toBe(720.0)
        ->and((float) $summary['total_break_minutes'])->toBe(60.0)
        ->and((float) $summary['total_worked_minutes'])->toBe(660.0)
        ->and((float) $summary['longest_break_minutes'])->toBe(45.0);

    $byStaff = collect($response->json('data.by_staff'));

    // Sorted by time actually worked, so the busiest employee leads.
    expect($byStaff->first()['staff_name'])->toBe('Alice Phiri')
        ->and((float) $byStaff->first()['scheduled_minutes'])->toBe(480.0)
        ->and((float) $byStaff->first()['break_minutes'])->toBe(60.0)
        ->and((float) $byStaff->first()['worked_minutes'])->toBe(420.0)
        ->and((float) $byStaff->first()['break_percentage'])->toBe(12.5)
        ->and((float) $byStaff->last()['worked_minutes'])->toBe(240.0);
});

it('lists every break taken with its type and length', function () {
    $alice = User::factory()->create(['name' => 'Alice Phiri', 'branch_id' => $this->lilongwe->id]);

    ($this->makeShift)($alice, $this->lilongwe, 8, [
        [ShiftBreakType::LUNCH, 45],
        [ShiftBreakType::PRAYER, 10],
    ]);

    $breaks = collect(
        $this->actingAs($this->storeAdmin)
            ->getJson('/api/v1/admin/reports/staff-time')
            ->assertSuccessful()
            ->json('data.breaks')
    );

    expect($breaks)->toHaveCount(2)
        ->and($breaks->pluck('type')->all())->toBe(['Lunch', 'Prayer'])
        ->and((float) $breaks->first()['minutes'])->toBe(45.0)
        ->and($breaks->first()['staff_name'])->toBe('Alice Phiri');
});

it('counts completed shifts only', function () {
    $alice = User::factory()->create(['branch_id' => $this->lilongwe->id]);

    ($this->makeShift)($alice, $this->lilongwe, 8);
    // Still on the clock: its end is unknown, so counting it would inflate the
    // period's worked total with time that has not finished happening.
    ($this->makeShift)($alice, $this->lilongwe, 2, [], 'active');
    // A cancelled shift is not time at work at all.
    ($this->makeShift)($alice, $this->lilongwe, 3, [], 'cancelled');

    $summary = $this->actingAs($this->storeAdmin)
        ->getJson('/api/v1/admin/reports/staff-time')
        ->assertSuccessful()
        ->json('data.summary');

    expect((int) $summary['total_shifts'])->toBe(1)
        ->and((float) $summary['total_scheduled_minutes'])->toBe(480.0);
});

it('pins store admins to their own branch', function () {
    $local = User::factory()->create(['branch_id' => $this->lilongwe->id]);
    $remote = User::factory()->create(['branch_id' => $this->blantyre->id]);

    ($this->makeShift)($local, $this->lilongwe, 8);
    ($this->makeShift)($remote, $this->blantyre, 8);

    $response = $this->actingAs($this->storeAdmin)
        ->getJson("/api/v1/admin/reports/staff-time?branch_id={$this->blantyre->id}&all_branches=1")
        ->assertSuccessful()
        ->assertJsonPath('data.filters.branch_id', $this->lilongwe->id);

    expect((int) $response->json('data.summary.total_shifts'))->toBe(1);
});

it('lets super admins report across every branch', function () {
    $local = User::factory()->create(['branch_id' => $this->lilongwe->id]);
    $remote = User::factory()->create(['branch_id' => $this->blantyre->id]);

    ($this->makeShift)($local, $this->lilongwe, 8);
    ($this->makeShift)($remote, $this->blantyre, 4);

    $summary = $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/admin/reports/staff-time?all_branches=1')
        ->assertSuccessful()
        ->json('data.summary');

    expect((int) $summary['total_shifts'])->toBe(2)
        ->and((float) $summary['total_scheduled_minutes'])->toBe(720.0);
});

it('filters the report by date range', function () {
    $alice = User::factory()->create(['branch_id' => $this->lilongwe->id]);

    $old = ($this->makeShift)($alice, $this->lilongwe, 8);
    $old->update([
        'started_at' => now()->subDays(20)->startOfSecond(),
        'ended_at' => now()->subDays(20)->addHours(8)->startOfSecond(),
    ]);

    ($this->makeShift)($alice, $this->lilongwe, 4);

    $summary = $this->actingAs($this->storeAdmin)
        ->getJson('/api/v1/admin/reports/staff-time?from='.now()->subDays(2)->toDateString().'&to='.now()->toDateString())
        ->assertSuccessful()
        ->json('data.summary');

    expect((int) $summary['total_shifts'])->toBe(1)
        ->and((float) $summary['total_scheduled_minutes'])->toBe(240.0);
});

it('streams a staff time workbook with the expected sheets', function () {
    $alice = User::factory()->create(['name' => 'Alice Phiri', 'branch_id' => $this->lilongwe->id]);
    ($this->makeShift)($alice, $this->lilongwe, 8, [[ShiftBreakType::LUNCH, 45]]);

    $response = $this->actingAs($this->storeAdmin)
        ->get('/api/v1/admin/reports/export?report=staff_time');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($response->headers->get('Content-Disposition'))->toContain('mufassah-staff_time-report');

    $path = tempnam(sys_get_temp_dir(), 'xlsx_staff_');
    file_put_contents($path, $response->streamedContent());

    $zip = new ZipArchive;
    $zip->open($path);

    $workbook = (string) $zip->getFromName('xl/workbook.xml');

    $sheetXml = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_starts_with($name, 'xl/worksheets/')) {
            $sheetXml .= (string) $zip->getFromName($name);
        }
    }

    $zip->close();
    @unlink($path);

    preg_match_all('/<sheet name="([^"]+)"/', $workbook, $matches);

    expect($matches[1])->toBe(['Summary', 'By staff', 'By day', 'Shift detail', 'Breaks']);

    // Same guard the sales workbook has: minute totals must be real numbers, or
    // the exported sheet cannot be summed or pivoted after it leaves the app.
    expect($sheetXml)->not->toMatch('/<t xml:space="preserve">-?[0-9]+(\.[0-9]+)?<\/t>/');
});
