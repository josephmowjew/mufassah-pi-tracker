<?php

use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\Family;
use App\Models\IotModule;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);

    // The inventory write routes sit behind `permission:manage-inventory`, which is
    // evaluated before the FormRequest runs - without it the bulk cases 403, not 422.
    $role->givePermissionTo(
        Permission::firstOrCreate(['name' => 'manage-inventory', 'guard_name' => 'api'])
    );

    $this->user = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

/**
 * Laravel's `exists:table,col` rule compiles to a raw query against the table and ignores
 * the model's SoftDeletes global scope, so a trashed parent stays usable as a reference
 * unless the rule carries an explicit ->whereNull('deleted_at').
 *
 * Each case is paired with a positive control: the rule must still ACCEPT a live row,
 * otherwise a rule that rejected everything would pass the negative test.
 *
 * Note on severity: several controllers already resolve the parent with a trashed-aware
 * lookup (findOrFail), so without the guard those paths returned a bare **404**. The guard
 * upgrades that to the correct **422** with a field-level message. The bulk-intake case is
 * the exception - there the validation rule is the only guard.
 */
describe('Soft-deleted parents are rejected as references', function () {
    it('rejects a soft-deleted device when filing a theft report', function () {
        $device = Device::factory()->create();
        $device->delete();

        $this->actingAs($this->user)->postJson('/api/v1/theft-reports', [
            'device_id' => $device->id,
            'theft_date' => now()->subDay()->toDateTimeString(),
            'theft_location' => 'Lilongwe',
            'description' => 'Stolen from the office.',
        ])->assertStatus(422)->assertJsonValidationErrors('device_id');
    });

    it('still accepts a live device when filing a theft report', function () {
        $device = Device::factory()->create();

        $this->actingAs($this->user)->postJson('/api/v1/theft-reports', [
            'device_id' => $device->id,
            'theft_date' => now()->subDay()->toDateTimeString(),
            'theft_location' => 'Lilongwe',
            'description' => 'Stolen from the office.',
        ])->assertJsonMissingValidationErrors('device_id');
    });

    it('rejects a soft-deleted user as a branch manager', function () {
        $manager = User::factory()->create();
        $manager->assignRole('super_admin');
        $manager->delete();

        $this->actingAs($this->admin)->postJson('/api/v1/admin/branches', [
            'name' => 'Ghost Manager Branch',
            'code' => 'GHO',
            'manager_id' => $manager->id,
            'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('manager_id');
    });

    // `families` is matched on `code`, not `id` - the only non-id reference in the set.
    it('rejects a soft-deleted family code when joining', function () {
        $family = Family::factory()->create(['code' => 'GONEFAM1']);
        $family->delete();

        $this->actingAs($this->user)->postJson('/api/v1/families/join', [
            'code' => 'GONEFAM1',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    });

    it('still accepts a live family code when joining', function () {
        Family::factory()->create(['code' => 'LIVEFAM1']);

        $this->actingAs($this->user)->postJson('/api/v1/families/join', [
            'code' => 'LIVEFAM1',
        ])->assertJsonMissingValidationErrors('code');
    });

    it('rejects a soft-deleted IoT module when receiving devices in bulk', function () {
        $module = IotModule::factory()->create();
        $module->delete();

        // Here the validation rule is the only guard: bulkStore() passes iot_module_id
        // straight into Device::create() without resolving the module first.
        $this->actingAs($this->admin)->postJson('/api/v1/admin/inventory/bulk', [
            'category_id' => DeviceCategory::factory()->create()->id,
            'branch_id' => Branch::create(['name' => 'Bulk Branch', 'code' => 'BLK', 'is_active' => true])->id,
            'iot_module_id' => $module->id,
            'brand' => 'TestBrand',
            'model' => 'TestModel',
            'quantity' => 1,
            'buying_price' => 100,
            'target_selling_price' => 150,
        ])->assertStatus(422)->assertJsonValidationErrors('iot_module_id');

        expect(Device::where('iot_module_id', $module->id)->count())->toBe(0);
    });

    it('still accepts a live IoT module when receiving devices in bulk', function () {
        $module = IotModule::factory()->create();

        $this->actingAs($this->admin)->postJson('/api/v1/admin/inventory/bulk', [
            'category_id' => DeviceCategory::factory()->create()->id,
            'branch_id' => Branch::create(['name' => 'Bulk Branch 2', 'code' => 'BLK2', 'is_active' => true])->id,
            'iot_module_id' => $module->id,
            'brand' => 'TestBrand',
            'model' => 'TestModel',
            'quantity' => 1,
            'buying_price' => 100,
            'target_selling_price' => 150,
        ])->assertJsonMissingValidationErrors('iot_module_id');
    });
});
