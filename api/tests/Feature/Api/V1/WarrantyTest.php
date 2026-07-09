<?php

use App\Enums\WarrantyClaimStatus;
use App\Enums\WarrantyStatus;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'customer_service', 'guard_name' => 'api']);
    Storage::fake('public');
});

describe('Warranty Listing', function () {
    it('returns warranties for device owner', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        // Create 2 devices for the user, each with a warranty
        $devices = Device::factory()->count(2)->for($user, 'owner')->for($category, 'category')->create();
        foreach ($devices as $device) {
            Warranty::factory()->create(['device_id' => $device->id]);
        }

        // Create warranties for other users' devices
        Warranty::factory()->count(3)->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl('warranties'))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('returns all warranties for admin', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Warranty::factory()->count(5)->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('warranties'))
            ->assertOk()
            ->assertJsonCount(5, 'data');
    });

    it('filters warranties by status', function () {
        $admin = User::factory()->create();
        $admin->assignRole('store_admin');

        Warranty::factory()->count(3)->create(['status' => WarrantyStatus::Active]);
        Warranty::factory()->count(2)->expired()->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('warranties?status=active'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters active warranties only', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Warranty::factory()->count(3)->create([
            'status' => WarrantyStatus::Active,
            'end_date' => now()->addYear(),
        ]);
        Warranty::factory()->count(2)->expired()->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('warranties?active_only=1'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });
});

describe('Warranty Creation (Admin)', function () {
    it('allows admin to create warranty', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $user = User::factory()->create();
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/warranties'), [
                'device_id' => $device->id,
                'start_date' => now()->toDateString(),
                'duration_months' => 24,
                'coverage_details' => 'Full hardware coverage',
                'provider' => 'TechShield Inc.',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'warranty_number', 'status', 'start_date', 'end_date'],
            ]);

        $this->assertDatabaseHas('warranties', [
            'device_id' => $device->id,
            'duration_months' => 24,
            'status' => WarrantyStatus::Active->value,
        ]);
    });

    it('prevents duplicate warranty for same device', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->create();

        // Create existing warranty
        Warranty::factory()->create(['device_id' => $device->id]);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/warranties'), [
                'device_id' => $device->id,
                'start_date' => now()->toDateString(),
                'duration_months' => 12,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id']);
    });

    it('validates required fields', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl('admin/warranties'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id', 'start_date', 'duration_months']);
    });

    it('prevents customer from creating warranty', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('admin/warranties'), [
                'device_id' => $device->id,
                'start_date' => now()->toDateString(),
                'duration_months' => 12,
            ])
            ->assertForbidden();
    });
});

describe('Warranty View', function () {
    it('allows device owner to view warranty', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->create(['device_id' => $device->id]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("warranties/{$warranty->id}"))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'warranty_number',
                    'status',
                    'start_date',
                    'end_date',
                    'is_active',
                    'days_remaining',
                ],
            ]);
    });

    it('prevents non-owner from viewing warranty', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $warranty = Warranty::factory()->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("warranties/{$warranty->id}"))
            ->assertForbidden();
    });

    it('allows viewing warranty for device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->create(['device_id' => $device->id]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/warranty"))
            ->assertOk()
            ->assertJsonPath('data.id', $warranty->id);
    });

    it('returns 404 when device has no warranty', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/warranty"))
            ->assertNotFound();
    });
});

describe('Warranty Update (Admin)', function () {
    it('allows admin to update warranty', function () {
        $admin = User::factory()->create();
        $admin->assignRole('store_admin');
        $warranty = Warranty::factory()->create();

        actingAs($admin, 'sanctum')
            ->putJson(apiUrl("warranties/{$warranty->id}"), [
                'coverage_details' => 'Extended coverage',
                'notes' => 'Updated by admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.coverage_details', 'Extended coverage');
    });

    it('prevents customer from updating warranty', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->create(['device_id' => $device->id]);

        actingAs($user, 'sanctum')
            ->putJson(apiUrl("warranties/{$warranty->id}"), [
                'coverage_details' => 'Trying to update',
            ])
            ->assertForbidden();
    });
});

describe('Warranty Void (Admin)', function () {
    it('allows super_admin to void warranty', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $warranty = Warranty::factory()->create(['status' => WarrantyStatus::Active]);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("warranties/{$warranty->id}/void"))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'voided');
    });

    it('prevents store_admin from voiding warranty', function () {
        $admin = User::factory()->create();
        $admin->assignRole('store_admin');
        $warranty = Warranty::factory()->create();

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("warranties/{$warranty->id}/void"))
            ->assertForbidden();
    });

    it('returns error when warranty already voided', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $warranty = Warranty::factory()->voided()->create();

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("warranties/{$warranty->id}/void"))
            ->assertBadRequest();
    });
});

describe('Warranty Deletion (Admin)', function () {
    it('allows super_admin to delete warranty without claims', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $warranty = Warranty::factory()->create();

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("warranties/{$warranty->id}"))
            ->assertOk();

        $this->assertSoftDeleted('warranties', ['id' => $warranty->id]);
    });

    it('prevents deletion of warranty with claims', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $warranty = Warranty::factory()->create();
        WarrantyClaim::factory()->create(['warranty_id' => $warranty->id]);

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("warranties/{$warranty->id}"))
            ->assertBadRequest();
    });
});

describe('Warranty Claim Submission', function () {
    it('allows device owner to submit claim', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->create([
            'device_id' => $device->id,
            'status' => WarrantyStatus::Active,
            'end_date' => now()->addYear(),
        ]);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("warranties/{$warranty->id}/claims"), [
                'issue_description' => 'Screen stopped working',
                'issue_category' => 'screen',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'claim_number', 'status', 'issue_description'],
            ]);

        $this->assertDatabaseHas('warranty_claims', [
            'warranty_id' => $warranty->id,
            'status' => WarrantyClaimStatus::Pending->value,
        ]);
    });

    it('handles evidence photo uploads', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->create([
            'device_id' => $device->id,
            'status' => WarrantyStatus::Active,
            'end_date' => now()->addYear(),
        ]);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("warranties/{$warranty->id}/claims"), [
                'issue_description' => 'Battery issue',
                'issue_category' => 'battery',
                'evidence_photos' => [
                    UploadedFile::fake()->image('photo1.jpg'),
                    UploadedFile::fake()->image('photo2.jpg'),
                ],
            ])
            ->assertCreated();

        $claim = WarrantyClaim::first();
        expect($claim->evidence_photos)->toHaveCount(2);
    });

    it('prevents claim submission for expired warranty', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->expired()->create(['device_id' => $device->id]);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("warranties/{$warranty->id}/claims"), [
                'issue_description' => 'Screen issue',
                'issue_category' => 'screen',
            ])
            ->assertBadRequest();
    });

    it('validates required fields', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->create([
            'device_id' => $device->id,
            'end_date' => now()->addYear(),
        ]);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("warranties/{$warranty->id}/claims"), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issue_description', 'issue_category']);
    });
});

describe('Warranty Claim Processing (Admin)', function () {
    it('allows admin to process claim', function () {
        $admin = User::factory()->create();
        $admin->assignRole('customer_service');
        $claim = WarrantyClaim::factory()->create(['status' => WarrantyClaimStatus::Pending]);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("warranty-claims/{$claim->id}/process"), [
                'status' => 'approved',
                'resolution_notes' => 'Claim approved for repair.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'approved');

        $claim->refresh();
        expect($claim->processed_by)->toBe($admin->id)
            ->and($claim->processed_at)->not->toBeNull();
    });

    it('requires resolution type when completing claim', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $claim = WarrantyClaim::factory()->approved()->create();

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("warranty-claims/{$claim->id}/process"), [
                'status' => 'completed',
                'resolution_notes' => 'Device repaired.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resolution_type']);
    });

    it('allows completing claim with resolution details', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $claim = WarrantyClaim::factory()->approved()->create();

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("warranty-claims/{$claim->id}/process"), [
                'status' => 'completed',
                'resolution_type' => 'repair',
                'resolution_notes' => 'Screen replaced.',
                'repair_cost' => 150.00,
            ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.resolution_type', 'repair');
    });

    it('prevents customer from processing claims', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $claim = WarrantyClaim::factory()->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("warranty-claims/{$claim->id}/process"), [
                'status' => 'approved',
                'resolution_notes' => 'Trying to process.',
            ])
            ->assertForbidden();
    });
});

describe('Warranty Claim Listing', function () {
    it('allows admin to list all claims', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        WarrantyClaim::factory()->count(5)->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('admin/warranty-claims'))
            ->assertOk()
            ->assertJsonCount(5, 'data');
    });

    it('filters claims by status', function () {
        $admin = User::factory()->create();
        $admin->assignRole('store_admin');

        WarrantyClaim::factory()->count(3)->create(['status' => WarrantyClaimStatus::Pending]);
        WarrantyClaim::factory()->count(2)->approved()->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('admin/warranty-claims?status=pending'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('lists claims for specific warranty', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->create(['device_id' => $device->id]);

        WarrantyClaim::factory()->count(2)->create(['warranty_id' => $warranty->id]);
        WarrantyClaim::factory()->count(3)->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("warranties/{$warranty->id}/claims"))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });
});

describe('Warranty Claim Deletion', function () {
    it('allows owner to delete pending claim', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->create(['device_id' => $device->id]);
        $claim = WarrantyClaim::factory()->create([
            'warranty_id' => $warranty->id,
            'status' => WarrantyClaimStatus::Pending,
        ]);

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("warranty-claims/{$claim->id}"))
            ->assertOk();

        $this->assertSoftDeleted('warranty_claims', ['id' => $claim->id]);
    });

    it('prevents deletion of non-pending claim by owner', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $warranty = Warranty::factory()->create(['device_id' => $device->id]);
        $claim = WarrantyClaim::factory()->approved()->create(['warranty_id' => $warranty->id]);

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("warranty-claims/{$claim->id}"))
            ->assertForbidden();
    });
});
