<?php

use App\Enums\DeviceStatus;
use App\Enums\TheftReportStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\TheftReport;
use App\Models\TheftReportUpdate;
use App\Models\User;
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

describe('Theft Report Listing', function () {
    it('returns theft reports for device owner', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        TheftReport::factory()->count(3)->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
        ]);

        // Create reports for other users
        TheftReport::factory()->count(2)->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl('theft-reports'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('returns all theft reports for admin', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        TheftReport::factory()->count(5)->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('theft-reports'))
            ->assertOk()
            ->assertJsonCount(5, 'data');
    });

    it('filters reports by status', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        TheftReport::factory()->count(3)->create(['status' => TheftReportStatus::Reported]);
        TheftReport::factory()->count(2)->investigating()->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('theft-reports?status=reported'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters open reports only', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        TheftReport::factory()->count(2)->create(['status' => TheftReportStatus::Reported]);
        TheftReport::factory()->count(1)->investigating()->create();
        TheftReport::factory()->count(2)->recovered()->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('theft-reports?open_only=1'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('searches by location or police report number', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        TheftReport::factory()->create(['theft_location' => 'Lilongwe City Center']);
        TheftReport::factory()->create(['police_report_number' => 'PR-123456']);
        TheftReport::factory()->create(['theft_location' => 'Blantyre']);

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl('theft-reports?search=Lilongwe'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('Theft Report Creation', function () {
    it('allows device owner to create theft report', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('theft-reports'), [
                'device_id' => $device->id,
                'theft_date' => now()->subDay()->toDateTimeString(),
                'theft_location' => 'Area 47, Lilongwe',
                'theft_latitude' => -13.9626,
                'theft_longitude' => 33.7741,
                'description' => 'Device was stolen from my car.',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'status', 'theft_date', 'theft_location', 'description'],
            ]);

        $this->assertDatabaseHas('theft_reports', [
            'device_id' => $device->id,
            'reported_by' => $user->id,
            'status' => TheftReportStatus::Reported->value,
        ]);

        // Device should be marked as stolen
        $device->refresh();
        expect($device->status)->toBe(DeviceStatus::STOLEN);
    });

    it('creates initial status update record', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('theft-reports'), [
                'device_id' => $device->id,
                'theft_date' => now()->subDay()->toDateTimeString(),
                'theft_location' => 'Lilongwe',
                'description' => 'Stolen device.',
            ])
            ->assertCreated();

        $report = TheftReport::first();
        expect($report->updates)->toHaveCount(1)
            ->and($report->updates->first()->new_status)->toBe(TheftReportStatus::Reported);
    });

    it('allows adding police report details', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('theft-reports'), [
                'device_id' => $device->id,
                'theft_date' => now()->subDay()->toDateTimeString(),
                'theft_location' => 'Lilongwe',
                'description' => 'Stolen device.',
                'police_report_number' => 'PR-123456',
                'police_station' => 'Area 3 Police Station',
                'officer_name' => 'Officer Banda',
                'officer_contact' => '+265999123456',
            ])
            ->assertCreated()
            ->assertJsonPath('data.police_report_number', 'PR-123456');
    });

    it('handles evidence photo uploads', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('theft-reports'), [
                'device_id' => $device->id,
                'theft_date' => now()->subDay()->toDateTimeString(),
                'theft_location' => 'Lilongwe',
                'description' => 'Stolen device.',
                'evidence_photos' => [
                    UploadedFile::fake()->image('evidence1.jpg'),
                    UploadedFile::fake()->image('evidence2.jpg'),
                ],
            ])
            ->assertCreated();

        $report = TheftReport::first();
        expect($report->evidence_photos)->toHaveCount(2);
        Storage::disk('public')->assertExists($report->evidence_photos[0]);
    });

    it('prevents reporting theft for device not owned', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $otherUser = User::factory()->create();
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($otherUser, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('theft-reports'), [
                'device_id' => $device->id,
                'theft_date' => now()->subDay()->toDateTimeString(),
                'theft_location' => 'Lilongwe',
                'description' => 'Stolen device.',
            ])
            ->assertForbidden();
    });

    it('prevents duplicate open theft reports for same device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        // Create existing open report
        TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
            'status' => TheftReportStatus::Reported,
        ]);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('theft-reports'), [
                'device_id' => $device->id,
                'theft_date' => now()->subDay()->toDateTimeString(),
                'theft_location' => 'Lilongwe',
                'description' => 'Another report.',
            ])
            ->assertBadRequest()
            ->assertJsonPath('message', 'This device already has an open theft report.');
    });

    it('validates required fields', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('theft-reports'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id', 'theft_date', 'theft_location', 'description']);
    });
});

describe('Theft Report View', function () {
    it('allows reporter to view their report', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $report = TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
        ]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("theft-reports/{$report->id}"))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'status',
                    'theft_date',
                    'theft_location',
                    'description',
                    'device',
                    'reporter',
                ],
            ]);
    });

    it('prevents non-owner from viewing report', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $report = TheftReport::factory()->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("theft-reports/{$report->id}"))
            ->assertForbidden();
    });

    it('allows admin to view any report', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $report = TheftReport::factory()->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl("theft-reports/{$report->id}"))
            ->assertOk();
    });
});

describe('Theft Report Update', function () {
    it('allows reporter to update their report', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $report = TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
        ]);

        actingAs($user, 'sanctum')
            ->putJson(apiUrl("theft-reports/{$report->id}"), [
                'description' => 'Updated description with more details.',
                'police_report_number' => 'PR-999999',
            ])
            ->assertOk()
            ->assertJsonPath('data.police_report_number', 'PR-999999');
    });

    it('prevents non-owner from updating report', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $report = TheftReport::factory()->create();

        actingAs($user, 'sanctum')
            ->putJson(apiUrl("theft-reports/{$report->id}"), [
                'description' => 'Hacked description.',
            ])
            ->assertForbidden();
    });
});

describe('Theft Report Deletion', function () {
    it('allows reporter to delete report in reported status', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create([
            'status' => DeviceStatus::STOLEN,
        ]);
        $report = TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
            'status' => TheftReportStatus::Reported,
        ]);

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("theft-reports/{$report->id}"))
            ->assertOk();

        $this->assertSoftDeleted('theft_reports', ['id' => $report->id]);

        // Device status should be reset
        $device->refresh();
        expect($device->status)->toBe(DeviceStatus::ACTIVE);
    });

    it('prevents deletion of report under investigation', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $report = TheftReport::factory()->investigating()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
        ]);

        // Policy denies deletion when report is under investigation
        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("theft-reports/{$report->id}"))
            ->assertForbidden();
    });

    it('allows super_admin to delete any report', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $report = TheftReport::factory()->create(['status' => TheftReportStatus::Reported]);

        actingAs($admin, 'sanctum')
            ->deleteJson(apiUrl("theft-reports/{$report->id}"))
            ->assertOk();
    });
});

describe('Theft Report Status Update', function () {
    it('allows admin to update report status', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $report = TheftReport::factory()->create(['status' => TheftReportStatus::Reported]);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("theft-reports/{$report->id}/status"), [
                'status' => 'investigating',
                'notes' => 'Starting investigation.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'investigating');

        $report->refresh();
        expect($report->updates)->toHaveCount(1);
    });

    it('marks device as active when recovered', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($category, 'category')->create(['status' => DeviceStatus::STOLEN]);
        $report = TheftReport::factory()->investigating()->create([
            'device_id' => $device->id,
        ]);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("theft-reports/{$report->id}/status"), [
                'status' => 'recovered',
                'notes' => 'Device found.',
                'recovery_location' => 'Area 49 Police Station',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'recovered');

        $device->refresh();
        expect($device->status)->toBe(DeviceStatus::ACTIVE);
    });

    it('requires recovery location when marking as recovered', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $report = TheftReport::factory()->investigating()->create();

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("theft-reports/{$report->id}/status"), [
                'status' => 'recovered',
                'notes' => 'Device found.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recovery_location']);
    });

    it('prevents customer from updating status', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $report = TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
        ]);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("theft-reports/{$report->id}/status"), [
                'status' => 'investigating',
                'notes' => 'Trying to change status.',
            ])
            ->assertForbidden();
    });
});

it('limits store admins to theft reports for devices in their branch', function () {
    $branch = Branch::create(['name' => 'Theft Branch', 'code' => 'THF', 'is_active' => true]);
    $otherBranch = Branch::create(['name' => 'Other Theft Branch', 'code' => 'OTF', 'is_active' => true]);
    $admin = User::factory()->create(['branch_id' => $branch->id]);
    $admin->assignRole('store_admin');
    $ownDevice = Device::factory()->create(['branch_id' => $branch->id]);
    $otherDevice = Device::factory()->create(['branch_id' => $otherBranch->id]);
    $ownReport = TheftReport::factory()->create(['device_id' => $ownDevice->id]);
    $otherReport = TheftReport::factory()->create(['device_id' => $otherDevice->id]);

    actingAs($admin, 'sanctum')
        ->getJson(apiUrl('theft-reports'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownReport->id);

    actingAs($admin, 'sanctum')
        ->getJson(apiUrl("theft-reports/{$otherReport->id}"))
        ->assertForbidden();
});

describe('Theft Report Assignment', function () {
    it('allows admin to assign report', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $agent = User::factory()->create();
        $agent->assignRole('customer_service');
        $report = TheftReport::factory()->create(['status' => TheftReportStatus::Reported]);

        actingAs($admin, 'sanctum')
            ->postJson(apiUrl("theft-reports/{$report->id}/assign"), [
                'assigned_to' => $agent->id,
                'notes' => 'Assigned for investigation.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'investigating');

        $report->refresh();
        expect($report->assigned_to)->toBe($agent->id)
            ->and($report->status)->toBe(TheftReportStatus::Investigating);
    });

    it('prevents customer_service from assigning report', function () {
        $agent = User::factory()->create();
        $agent->assignRole('customer_service');
        $report = TheftReport::factory()->create();

        actingAs($agent, 'sanctum')
            ->postJson(apiUrl("theft-reports/{$report->id}/assign"), [
                'assigned_to' => $agent->id,
            ])
            ->assertForbidden();
    });
});

describe('Evidence Management', function () {
    it('allows reporter to add evidence', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $report = TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
            'evidence_photos' => [],
        ]);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("theft-reports/{$report->id}/evidence"), [
                'photos' => [
                    UploadedFile::fake()->image('evidence1.jpg'),
                    UploadedFile::fake()->image('evidence2.jpg'),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.added_photos', fn ($photos) => count($photos) === 2);

        $report->refresh();
        expect($report->evidence_photos)->toHaveCount(2);
    });

    it('allows reporter to remove evidence', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        // Create file in storage
        Storage::disk('public')->put('evidence/test.jpg', 'test content');

        $report = TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
            'evidence_photos' => ['evidence/test.jpg'],
        ]);

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("theft-reports/{$report->id}/evidence"), [
                'photo_path' => 'evidence/test.jpg',
            ])
            ->assertOk();

        $report->refresh();
        expect($report->evidence_photos)->toBeEmpty();
        Storage::disk('public')->assertMissing('evidence/test.jpg');
    });

    it('returns error when removing non-existent evidence', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $report = TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
            'evidence_photos' => [],
        ]);

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("theft-reports/{$report->id}/evidence"), [
                'photo_path' => 'nonexistent.jpg',
            ])
            ->assertNotFound();
    });
});

describe('Theft Report Updates/History', function () {
    it('returns public updates for reporter', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $report = TheftReport::factory()->create([
            'device_id' => $device->id,
            'reported_by' => $user->id,
        ]);

        TheftReportUpdate::factory()->count(2)->create([
            'theft_report_id' => $report->id,
            'is_public' => true,
        ]);
        TheftReportUpdate::factory()->private()->create([
            'theft_report_id' => $report->id,
        ]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("theft-reports/{$report->id}/updates"))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('returns all updates for admin', function () {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $report = TheftReport::factory()->create();

        TheftReportUpdate::factory()->count(2)->create([
            'theft_report_id' => $report->id,
            'is_public' => true,
        ]);
        TheftReportUpdate::factory()->private()->count(3)->create([
            'theft_report_id' => $report->id,
        ]);

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl("theft-reports/{$report->id}/updates"))
            ->assertOk()
            ->assertJsonCount(5, 'data');
    });
});
