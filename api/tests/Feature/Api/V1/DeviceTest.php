<?php

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\DeviceOwnershipHistory;
use App\Models\IotModule;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);
    Storage::fake('public');
});

describe('Device Listing', function () {
    it('returns paginated devices for authenticated user', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        Device::factory()->count(3)->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl('devices'))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'unique_device_code', 'serial_number', 'brand', 'model', 'status'],
                ],
                'meta' => ['current_page', 'total', 'per_page'],
            ])
            ->assertJsonCount(3, 'data');
    });

    it('only returns devices owned by the authenticated user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        Device::factory()->count(2)->for($user, 'owner')->for($category, 'category')->create();
        Device::factory()->count(3)->for($otherUser, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl('devices'))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('filters devices by status', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        Device::factory()->for($user, 'owner')->for($category, 'category')->create(['status' => DeviceStatus::ACTIVE]);
        Device::factory()->for($user, 'owner')->for($category, 'category')->create(['status' => DeviceStatus::INACTIVE]);
        Device::factory()->for($user, 'owner')->for($category, 'category')->create(['status' => DeviceStatus::STOLEN]);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl('devices?status=inactive'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('searches devices by serial number', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        Device::factory()->for($user, 'owner')->for($category, 'category')->create(['serial_number' => 'ABC123XYZ']);
        Device::factory()->for($user, 'owner')->for($category, 'category')->create(['serial_number' => 'DEF456']);

        actingAs($user, 'sanctum')
            ->getJson(apiUrl('devices?search=ABC123'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('requires authentication', function () {
        getJson(apiUrl('devices'))
            ->assertUnauthorized();
    });
});

describe('Device Creation', function () {
    it('allows authenticated user to register a device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices'), [
                'serial_number' => 'SN123456789',
                'brand' => 'Apple',
                'model' => 'iPhone 14',
                'category_id' => $category->id,
                'purchase_date' => '2024-01-15',
                'notes' => 'My primary phone',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'unique_device_code',
                    'serial_number',
                    'brand',
                    'model',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('devices', [
            'serial_number' => 'SN123456789',
            'brand' => 'Apple',
            'owner_id' => $user->id,
        ]);
    });

    it('generates unique device code automatically', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        $response = actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices'), [
                'serial_number' => 'SN123456789',
                'brand' => 'Samsung',
                'model' => 'Galaxy S24',
                'category_id' => $category->id,
            ]);

        $response->assertCreated();
        expect($response->json('data.unique_device_code'))->toStartWith('MUF-');
    });

    it('creates initial ownership history record', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices'), [
                'serial_number' => 'SN123456789',
                'brand' => 'Samsung',
                'model' => 'Galaxy S24',
                'category_id' => $category->id,
            ])
            ->assertCreated();

        $device = Device::where('serial_number', 'SN123456789')->first();
        $history = DeviceOwnershipHistory::where('device_id', $device->id)->first();

        expect($history)->not->toBeNull()
            ->and($history->to_user_id)->toBe($user->id)
            ->and($history->from_user_id)->toBeNull();
    });

    it('validates required fields', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number', 'brand', 'model', 'category_id']);
    });

    it('validates category exists', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices'), [
                'serial_number' => 'SN123456789',
                'brand' => 'Apple',
                'model' => 'iPhone 14',
                'category_id' => 999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    });

    it('handles photo uploads during creation', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();

        $response = actingAs($user, 'sanctum')
            ->postJson(apiUrl('devices'), [
                'serial_number' => 'SN123456789',
                'brand' => 'Apple',
                'model' => 'iPhone 14',
                'category_id' => $category->id,
                'photos' => [
                    UploadedFile::fake()->image('device1.jpg'),
                    UploadedFile::fake()->image('device2.jpg'),
                ],
            ]);

        $response->assertCreated();

        $device = Device::where('serial_number', 'SN123456789')->first();
        expect($device->photos)->toHaveCount(2);
    });
});

describe('Device View', function () {
    it('allows owner to view their device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}"))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'unique_device_code',
                    'serial_number',
                    'brand',
                    'model',
                    'status',
                    'category',
                    'owner',
                ],
            ]);
    });

    it('prevents non-owner from viewing device', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($otherUser, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}"))
            ->assertForbidden();
    });

    it('allows admin to view any device', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($admin, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}"))
            ->assertOk();
    });
});

describe('Device Update', function () {
    it('allows owner to update their device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->putJson(apiUrl("devices/{$device->id}"), [
                'brand' => 'Updated Brand',
                'notes' => 'Updated notes',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'brand' => 'Updated Brand',
                    'notes' => 'Updated notes',
                ],
            ]);
    });

    it('prevents non-owner from updating device', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($otherUser, 'sanctum')
            ->putJson(apiUrl("devices/{$device->id}"), [
                'brand' => 'Hacked Brand',
            ])
            ->assertForbidden();
    });
});

describe('Device Deletion', function () {
    it('allows owner to delete their device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("devices/{$device->id}"))
            ->assertOk();

        $this->assertSoftDeleted('devices', ['id' => $device->id]);
    });

    it('prevents non-owner from deleting device', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($otherUser, 'sanctum')
            ->deleteJson(apiUrl("devices/{$device->id}"))
            ->assertForbidden();
    });
});

describe('Device Status Update', function () {
    it('allows owner to update device status', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create([
            'status' => DeviceStatus::ACTIVE,
        ]);

        actingAs($user, 'sanctum')
            ->putJson(apiUrl("devices/{$device->id}/status"), [
                'status' => 'stolen',
                'notes' => 'Stolen at the mall',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => [
                        'value' => 'stolen',
                    ],
                ],
            ]);
    });

    it('validates status value', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->putJson(apiUrl("devices/{$device->id}/status"), [
                'status' => 'invalid_status',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });
});

describe('Device Transfer', function () {
    it('allows owner to transfer device to another user', function () {
        $owner = User::factory()->create();
        $newOwner = User::factory()->create(['email' => 'newowner@example.com']);
        $owner->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($owner, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/transfer"), [
                'to_user_email' => 'newowner@example.com',
                'transfer_reason' => 'Sold the device',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $device->refresh();
        expect($device->owner_id)->toBe($newOwner->id);
    });

    it('creates ownership history record on transfer', function () {
        $owner = User::factory()->create();
        $newOwner = User::factory()->create(['email' => 'buyer@example.com']);
        $owner->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($owner, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/transfer"), [
                'to_user_email' => 'buyer@example.com',
                'transfer_reason' => 'Gift',
            ])
            ->assertOk();

        $history = DeviceOwnershipHistory::where('device_id', $device->id)
            ->where('to_user_id', $newOwner->id)
            ->first();

        expect($history)->not->toBeNull()
            ->and($history->from_user_id)->toBe($owner->id)
            ->and($history->transfer_reason)->toBe('Gift');
    });

    it('fails to transfer to non-existent user', function () {
        $owner = User::factory()->create();
        $owner->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($owner, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/transfer"), [
                'to_user_email' => 'nonexistent@example.com',
                'transfer_reason' => 'Test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_user_email']);
    });

    it('fails to transfer to same owner', function () {
        $owner = User::factory()->create();
        $owner->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        actingAs($owner, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/transfer"), [
                'to_user_email' => $owner->email,
                'transfer_reason' => 'Test',
            ])
            ->assertBadRequest();
    });
});

describe('Device Photo Management', function () {
    it('allows uploading photos to a device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/photos"), [
                'photos' => [
                    UploadedFile::fake()->image('photo1.jpg'),
                    UploadedFile::fake()->image('photo2.jpg'),
                ],
            ])
            ->assertOk();

        $device->refresh();
        expect($device->photos)->toHaveCount(2);
    });

    it('limits photos to 5 maximum', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create([
            'photos' => ['old1.jpg', 'old2.jpg', 'old3.jpg'],
        ]);

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/photos"), [
                'photos' => [
                    UploadedFile::fake()->image('new1.jpg'),
                    UploadedFile::fake()->image('new2.jpg'),
                    UploadedFile::fake()->image('new3.jpg'),
                ],
            ])
            ->assertOk();

        $device->refresh();
        expect($device->photos)->toHaveCount(5);
    });

    it('allows deleting a photo', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create([
            'photos' => ['photo1.jpg', 'photo2.jpg'],
        ]);

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("devices/{$device->id}/photos"), [
                'photo_index' => 0,
            ])
            ->assertOk();

        $device->refresh();
        expect($device->photos)->toHaveCount(1);
    });
});

describe('Device IoT Module Linking', function () {
    it('allows linking an IoT module to device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create();
        $module = IotModule::factory()->create();

        actingAs($user, 'sanctum')
            ->postJson(apiUrl("devices/{$device->id}/link-module"), [
                'iot_module_id' => $module->id,
            ])
            ->assertOk();

        $device->refresh();
        expect($device->iot_module_id)->toBe($module->id);
    });

    it('allows unlinking an IoT module from device', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $module = IotModule::factory()->create();
        $device = Device::factory()
            ->for($user, 'owner')
            ->for($category, 'category')
            ->for($module, 'iotModule')
            ->create();

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("devices/{$device->id}/unlink-module"))
            ->assertOk();

        $device->refresh();
        expect($device->iot_module_id)->toBeNull();
    });

    it('fails to unlink when no module linked', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($user, 'owner')->for($category, 'category')->create([
            'iot_module_id' => null,
        ]);

        actingAs($user, 'sanctum')
            ->deleteJson(apiUrl("devices/{$device->id}/unlink-module"))
            ->assertBadRequest();
    });
});

describe('Device Ownership History', function () {
    it('returns ownership history for a device', function () {
        $owner = User::factory()->create();
        $owner->assignRole('customer');
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->for($owner, 'owner')->for($category, 'category')->create();

        DeviceOwnershipHistory::factory()->create([
            'device_id' => $device->id,
            'from_user_id' => null,
            'to_user_id' => $owner->id,
        ]);

        actingAs($owner, 'sanctum')
            ->getJson(apiUrl("devices/{$device->id}/ownership-history"))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'from_user', 'to_user', 'transfer_reason', 'transferred_at'],
                ],
            ]);
    });
});
