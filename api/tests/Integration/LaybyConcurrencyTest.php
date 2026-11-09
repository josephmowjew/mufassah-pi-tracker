<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\InventoryBatch;
use App\Models\Layby;
use App\Models\LaybyBranchPolicy;
use App\Models\LaybyEntry;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('serializes competing stock, payment, handover and payout requests on MySQL', function () {
    if (config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'mufassah_layby_test'
        || config('database.connections.mysql.url')
        || ! config('database.connections.mysql.unix_socket')
        || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Use the isolated mufassah_layby_test MySQL database and PHP PCNTL.');
    }

    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(now()->setDate(2026, 11, 9)->setTime(8, 0));
    $branch = Branch::create(['name' => 'Concurrency pilot', 'code' => 'RACE', 'is_active' => true]);
    $manager = User::factory()->create(['branch_id' => $branch->id])->assignRole('store_admin');
    $customer = User::factory()->create()->assignRole('customer');
    $cashiers = User::factory()->count(2)->create(['branch_id' => $branch->id])->each(function (User $user) use ($branch) {
        $user->assignRole('customer_service');
        Shift::create(['branch_id' => $branch->id, 'user_id' => $user->id, 'started_at' => now(), 'status' => 'active']);
    });
    LaybyBranchPolicy::create(['branch_id' => $branch->id, 'enabled' => true, 'review_reference' => 'Test approval',
        'merchant_channels' => [], 'updated_by_id' => $manager->id]);
    $device = Device::factory()->create(['branch_id' => $branch->id, 'owner_id' => null,
        'status' => 'active', 'inventory_status' => InventoryStatus::IN_STOCK]);
    $batch = InventoryBatch::create(['batch_number' => 'CONCURRENCY', 'branch_id' => $branch->id, 'category_id' => $device->category_id,
        'brand' => $device->brand, 'model' => $device->model, 'quantity_received' => 1, 'buying_price' => 500, 'target_selling_price' => 1000]);
    $device->update(['inventory_batch_id' => $batch->id]);

    $call = fn (User $actor, string $path, array $body) => $this->actingAs($actor)->postJson(
        '/api/v1/laybys'.$path, $body, ['Idempotency-Key' => (string) Str::uuid()]
    );
    $race = function (array ...$requests) use ($call): array {
        // Every worker starts its own database connection after the barrier.
        DB::purge();
        $workers = [];
        foreach ($requests as $request) {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork concurrency worker.');
            }
            if ($pid === 0) {
                fclose($parent);
                fread($child, 1);
                try {
                    $response = $call(...$request);
                    fwrite($child, (string) $response->status());
                } catch (Throwable $exception) {
                    fwrite($child, $exception->getMessage());
                }
                fclose($child);
                exit(0);
            }
            fclose($child);
            stream_set_timeout($parent, 30);
            $workers[] = [$pid, $parent];
        }
        foreach ($workers as [, $socket]) {
            fwrite($socket, '1');
        }
        $outcomes = [];
        foreach ($workers as [$pid, $socket]) {
            $outcomes[] = stream_get_contents($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
        }
        sort($outcomes);

        return $outcomes;
    };

    $quote = $call($cashiers[0], '/quote', ['device_id' => $device->id, 'customer_id' => $customer->id])->assertOk()->json('quote');
    $open = ['quote' => $quote, 'amount_minor' => 23500, 'terms_accepted' => true, 'cash_received' => true];
    expect($race([$cashiers[0], '', $open], [$cashiers[1], '', $open]))->toBe(['201', '422']);
    $agreement = Layby::sole();
    expect(LaybyEntry::where('kind', 'collection')->count())->toBe(1)
        ->and($device->fresh()->reserved_layby_id)->toBe($agreement->id);

    $path = '/'.$agreement->id;
    $payment = ['tender' => 'cash', 'amount_minor' => 94000, 'cash_received' => true];
    expect($race([$cashiers[0], $path.'/collect', $payment], [$cashiers[1], $path.'/collect', $payment]))->toBe(['200', '422']);
    expect($agreement->fresh()->netReceived())->toBe(117500);
    $pickup = ['serial_number' => $device->serial_number, 'evidence' => 'Checked pickup', 'verified_pickup' => true];
    $cancel = ['faulty' => false, 'evidence' => 'Customer request'];
    expect($race([$cashiers[0], $path.'/handover', $pickup], [$manager, $path.'/cancel', $cancel]))->toBe(['200', '422']);
    $agreement->refresh();
    if ($agreement->status === 'fulfilled') {
        expect(Sale::count())->toBe(1)->and($device->fresh()->owner_id)->toBe($customer->id);
        $call($manager, $path.'/return', $cancel + ['serial_number' => $device->serial_number, 'physically_received' => true])->assertOk();
    } else {
        expect($agreement->status)->toBe('cancelled')->and(Sale::count())->toBe(0);
    }
    expect($device->fresh()->reserved_layby_id)->toBeNull();

    $source = ['entry_id' => $agreement->entries()->where('kind', 'collection')->firstOrFail()->id, 'evidence' => 'Cash returned'];
    $call($manager, $path.'/refund-approve', $source)->assertOk();
    expect($race([$cashiers[0], $path.'/refund-claim', $source], [$cashiers[1], $path.'/refund-claim', $source]))->toBe(['200', '422']);
    expect(LaybyEntry::where('kind', 'refund_claimed')->count())->toBe(1);
});
