<?php

use App\Models\Branch;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * Regression cover for E-04: raw exception text must never reach an API client.
 *
 * Two independent paths are covered —
 *   1. the catch-all renderer in bootstrap/app.php, which owns everything the
 *      framework would otherwise render itself, and
 *   2. ShiftController::end(), which catches its own exception and therefore
 *      never passes through that renderer at all.
 */

describe('Unhandled exception rendering', function () {
    it('keeps the documented envelope and leaks nothing, even with debug on', function () {
        // Debug on is the configuration that actually leaks: the framework's own
        // renderer then returns `exception`, `file`, `line` and `trace` in the body.
        config(['app.debug' => true]);

        Route::get('api/v1/__test/boom', function () {
            throw new RuntimeException(
                "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'mufassah.secret_table' doesn't exist "
                .'(Connection: mysql, SQL: select * from `secret_table`)'
            );
        });

        $response = $this->getJson('/api/v1/__test/boom');

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An unexpected error occurred. Please try again later.');

        $body = $response->json();

        expect($body)->not->toHaveKey('trace')
            ->and($body)->not->toHaveKey('exception')
            ->and($body)->not->toHaveKey('file')
            ->and($body)->not->toHaveKey('line');

        $raw = json_encode($body);

        expect($raw)->not->toContain('SQLSTATE')
            ->and($raw)->not->toContain('secret_table')
            ->and($raw)->not->toContain('mysql');
    });

    it('does not mask an abort() status as a server error', function () {
        // A 409 is not a server fault. The catch-all must decline it so the
        // framework keeps the status the caller deliberately chose.
        Route::get('api/v1/__test/conflict', function () {
            abort(409, 'The request conflicts with the current state of the resource.');
        });

        $this->getJson('/api/v1/__test/conflict')->assertStatus(409);
    });

    it('does not mask a 404 as a server error', function () {
        $this->getJson('/api/v1/__test/no-such-route')->assertStatus(404);
    });
});

describe('Shift end failure', function () {
    beforeEach(function () {
        $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Test Branch',
            'code' => 'TST',
            'is_active' => true,
        ]);

        $this->agent = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->agent->assignRole('customer_service');

        $this->shift = Shift::create([
            'user_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'started_at' => now()->subHour(),
            'status' => 'active',
            'opening_cash' => 1000,
        ]);
    });

    it('returns a generic message and logs the failure instead of echoing the SQL error', function () {
        // Inject the failure WITHOUT DDL. The previous version dropped the `ended_at`
        // column, but MySQL implicitly commits DDL inside a transaction, which
        // desynchronises RefreshDatabase's transaction wrapper: the controller's
        // DB::rollBack() then threw, that escaped its own catch, and the catch-all
        // renderer answered instead. The assertion therefore passed on SQLite and
        // failed on MySQL. A model event fails the write on any driver.
        Shift::saving(function () {
            throw new \RuntimeException('Simulated failure while completing the shift.');
        });

        Log::spy();

        $response = $this->actingAs($this->agent)
            ->postJson('/api/v1/customer-service/shifts/end', ['closing_cash' => 1500]);

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to end shift. Please try again.');

        $raw = json_encode($response->json());

        expect($raw)->not->toContain('ended_at')
            ->and($raw)->not->toContain('SQLSTATE')
            ->and($raw)->not->toContain('no such column')
            ->and($raw)->not->toContain('ShiftController');

        // The controller swallows the exception, so nothing else will report it.
        Log::shouldHaveReceived('error');
    });

    it('still ends a shift successfully when nothing goes wrong', function () {
        $response = $this->actingAs($this->agent)
            ->postJson('/api/v1/customer-service/shifts/end', ['closing_cash' => 1500]);

        $response->assertOk()->assertJsonPath('success', true);

        expect($this->shift->fresh()->status)->toBe('completed')
            ->and($this->shift->fresh()->closing_cash)->toBe('1500.00');
    });
});
