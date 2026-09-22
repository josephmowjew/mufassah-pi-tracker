<?php

use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\StockTransferRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

describe('Admin Branch Management', function () {
    it('creates and updates branch details', function () {
        $manager = User::factory()->create();
        $manager->assignRole('super_admin');

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/branches', [
            'name' => 'Mzuzu Branch',
            'code' => 'MZU',
            'city' => 'Mzuzu',
            'phone' => '+265999111222',
            'email' => 'mzuzu@example.test',
            'manager_id' => $manager->id,
            'is_active' => true,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Mzuzu Branch')
            ->assertJsonPath('data.code', 'MZU');

        $branchId = $response->json('data.id');

        $this->actingAs($this->admin)->putJson("/api/v1/admin/branches/{$branchId}", [
            'name' => 'Mzuzu Main Branch',
            'code' => 'MZU',
            'is_active' => false,
        ])->assertSuccessful()
            ->assertJsonPath('data.name', 'Mzuzu Main Branch')
            ->assertJsonPath('data.is_active', false);
    });

    it('does not delete branches with linked stock records', function () {
        $branch = Branch::create([
            'name' => 'Linked Branch',
            'code' => 'LNK',
            'city' => 'Blantyre',
            'is_active' => true,
        ]);

        Device::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/branches/{$branch->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This branch has linked records. Deactivate it instead of deleting it.');
    });

    it('assigns a store admin manager to the managed branch', function () {
        $manager = User::factory()->create();
        $manager->assignRole('store_admin');

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/branches', [
            'name' => 'Lilongwe Branch',
            'code' => 'LLW',
            'manager_id' => $manager->id,
            'is_active' => true,
        ]);

        $response->assertSuccessful();
        expect($manager->fresh()->branch_id)->toBe($response->json('data.id'));
    });

    it('only lets store admins view their branch and reserves branch management for super admins', function () {
        $branch = Branch::create(['name' => 'Store Branch', 'code' => 'STR', 'is_active' => true]);
        Branch::create(['name' => 'Hidden Branch', 'code' => 'HID', 'is_active' => true]);
        $storeAdmin = User::factory()->create(['branch_id' => $branch->id]);
        $storeAdmin->assignRole('store_admin');

        $this->actingAs($storeAdmin)
            ->getJson('/api/v1/admin/branches?per_page=100')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $branch->id);

        $this->actingAs($storeAdmin)
            ->postJson('/api/v1/admin/branches', [
                'name' => 'Unauthorized Branch',
                'code' => 'UNA',
                'is_active' => true,
            ])
            ->assertForbidden();
    });

    it('refuses to delete a branch that has a stock transfer request', function () {
        $branch = Branch::create(['name' => 'Transfer Branch', 'code' => 'TRF', 'is_active' => true]);
        $other = Branch::create(['name' => 'Other Branch', 'code' => 'OTH', 'is_active' => true]);

        StockTransferRequest::create([
            'request_number' => 'STR-GUARD-1',
            'from_branch_id' => $branch->id,
            'to_branch_id' => $other->id,
            'requested_by_id' => $this->admin->id,
            'reason' => 'Rebalance stock',
        ]);

        // Regression: the guard used to omit stock_transfer_requests. It passed, then
        // the RESTRICT foreign key raised SQLSTATE 1451, which surfaced as a 500 with
        // the generic message instead of this 422. Note the transfer is not PENDING,
        // and RESTRICT blocks the delete for any status.
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/branches/{$branch->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This branch has linked records. Deactivate it instead of deleting it.')
            ->assertJsonPath('linked_records.stock_transfer_requests', 1);

        expect(Branch::whereKey($branch->id)->exists())->toBeTrue();
    });

    it('deletes a branch that has no linked records', function () {
        $branch = Branch::create(['name' => 'Empty Branch', 'code' => 'EMP', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/branches/{$branch->id}")
            ->assertNoContent();

        expect(Branch::whereKey($branch->id)->exists())->toBeFalse();
    });

    it('refuses at the database level to hard delete a branch that still has stock', function () {
        // The RESTRICT migration cannot run on SQLite (it cannot drop foreign keys),
        // so on SQLite the cascade is still in place and this guarantee does not hold.
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The RESTRICT migration is MySQL-only.');
        }

        $branch = Branch::create(['name' => 'Stock Branch', 'code' => 'STK', 'is_active' => true]);

        InventoryBatch::create([
            'batch_number' => 'BATCH-GUARD-1',
            'branch_id' => $branch->id,
            'category_id' => DeviceCategory::factory()->create()->id,
            'brand' => 'TestBrand',
            'model' => 'TestModel',
            'quantity_received' => 5,
            'buying_price' => 100,
            'target_selling_price' => 150,
        ]);

        // Bypass the controller guard completely - this is the tinker / forceDelete
        // path. Before the migration this silently CASCADE-deleted the batch.
        expect(fn () => $branch->delete())->toThrow(QueryException::class);

        expect(Branch::whereKey($branch->id)->exists())->toBeTrue()
            ->and(InventoryBatch::where('branch_id', $branch->id)->count())->toBe(1);
    });
});
