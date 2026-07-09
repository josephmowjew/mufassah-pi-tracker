<?php

use App\Enums\DeviceStatus;
use App\Enums\IotModuleStatus;
use App\Enums\ModemType;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\DeviceLocation;
use App\Models\DeviceOwnershipHistory;
use App\Models\IotModule;
use App\Models\User;

describe('DeviceCategory Model', function () {
    it('can be created with factory', function () {
        $category = DeviceCategory::factory()->create();

        expect($category)->toBeInstanceOf(DeviceCategory::class)
            ->and($category->name)->toBeString()
            ->and($category->slug)->toBeString()
            ->and($category->is_active)->toBeTrue();
    });

    it('can be created as inactive', function () {
        $category = DeviceCategory::factory()->inactive()->create();

        expect($category->is_active)->toBeFalse();
    });

    it('has devices relationship', function () {
        $category = DeviceCategory::factory()->create();

        expect($category->devices())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('can scope to active categories', function () {
        DeviceCategory::factory()->count(2)->create();
        DeviceCategory::factory()->inactive()->create();

        $activeCategories = DeviceCategory::active()->get();

        expect($activeCategories)->toHaveCount(2);
    });
});

describe('IotModule Model', function () {
    it('can be created with factory', function () {
        $module = IotModule::factory()->create();

        expect($module)->toBeInstanceOf(IotModule::class)
            ->and($module->module_id)->toStartWith('MOD-')
            ->and($module->status)->toBeInstanceOf(IotModuleStatus::class)
            ->and($module->modem_type)->toBeInstanceOf(ModemType::class);
    });

    it('can be created as inactive', function () {
        $module = IotModule::factory()->inactive()->create();

        expect($module->status)->toBe(IotModuleStatus::Inactive)
            ->and($module->activated_at)->toBeNull();
    });

    it('can be created as offline', function () {
        $module = IotModule::factory()->offline()->create();

        expect($module->status)->toBe(IotModuleStatus::Offline);
    });

    it('supports soft deletes', function () {
        $module = IotModule::factory()->create();
        $module->delete();

        expect(IotModule::count())->toBe(0)
            ->and(IotModule::withTrashed()->count())->toBe(1);
    });

    it('can scope to active modules', function () {
        IotModule::factory()->count(2)->create();
        IotModule::factory()->inactive()->create();

        $activeModules = IotModule::active()->get();

        expect($activeModules)->toHaveCount(2);
    });
});

describe('Device Model', function () {
    it('can be created with factory', function () {
        $device = Device::factory()->create();

        expect($device)->toBeInstanceOf(Device::class)
            ->and($device->unique_device_code)->toStartWith('DEV-')
            ->and($device->status)->toBe(DeviceStatus::ACTIVE);
    });

    it('can be created as stolen', function () {
        $device = Device::factory()->stolen()->create();

        expect($device->status)->toBe(DeviceStatus::STOLEN)
            ->and($device->isStolen())->toBeTrue();
    });

    it('belongs to an owner', function () {
        $user = User::factory()->create();
        $device = Device::factory()->ownedBy($user)->create();

        expect($device->owner)->toBeInstanceOf(User::class)
            ->and($device->owner->id)->toBe($user->id);
    });

    it('belongs to a category', function () {
        $category = DeviceCategory::factory()->create();
        $device = Device::factory()->inCategory($category)->create();

        expect($device->category)->toBeInstanceOf(DeviceCategory::class)
            ->and($device->category->id)->toBe($category->id);
    });

    it('can have an IoT module attached', function () {
        $device = Device::factory()->withIotModule()->create();

        expect($device->hasIotModule())->toBeTrue()
            ->and($device->iotModule)->toBeInstanceOf(IotModule::class);
    });

    it('supports soft deletes', function () {
        $device = Device::factory()->create();
        $device->delete();

        expect(Device::count())->toBe(0)
            ->and(Device::withTrashed()->count())->toBe(1);
    });

    it('can scope to active devices', function () {
        Device::factory()->count(2)->create();
        Device::factory()->stolen()->create();

        $activeDevices = Device::active()->get();

        expect($activeDevices)->toHaveCount(2);
    });

    it('can scope to stolen devices', function () {
        Device::factory()->count(2)->create();
        Device::factory()->stolen()->count(3)->create();

        $stolenDevices = Device::stolen()->get();

        expect($stolenDevices)->toHaveCount(3);
    });

    it('can scope to owned by user', function () {
        $user = User::factory()->create();
        Device::factory()->ownedBy($user)->count(2)->create();
        Device::factory()->count(3)->create();

        $userDevices = Device::ownedBy($user->id)->get();

        expect($userDevices)->toHaveCount(2);
    });
});

describe('DeviceLocation Model', function () {
    it('can be created with factory', function () {
        $location = DeviceLocation::factory()->create();

        expect($location)->toBeInstanceOf(DeviceLocation::class)
            ->and($location->latitude)->toBeNumeric()
            ->and($location->longitude)->toBeNumeric()
            ->and($location->is_manual)->toBeFalse();
    });

    it('can be created as manual location', function () {
        $location = DeviceLocation::factory()->manual()->create();

        expect($location->is_manual)->toBeTrue()
            ->and($location->iot_module_id)->toBeNull();
    });

    it('belongs to a device', function () {
        $device = Device::factory()->create();
        $location = DeviceLocation::factory()->forDevice($device)->create();

        expect($location->device)->toBeInstanceOf(Device::class)
            ->and($location->device->id)->toBe($device->id);
    });

    it('can calculate distance to another point', function () {
        $location = DeviceLocation::factory()->atCoordinates(0, 0)->create();

        $distance = $location->distanceTo(0.01, 0.01);

        expect($distance)->toBeGreaterThan(0);
    });

    it('returns zero distance for same coordinates', function () {
        $location = DeviceLocation::factory()->atCoordinates(10.5, 20.5)->create();

        $distance = $location->distanceTo(10.5, 20.5);

        expect($distance)->toBe(0.0);
    });

    it('can scope to manual locations', function () {
        DeviceLocation::factory()->count(2)->create();
        DeviceLocation::factory()->manual()->count(3)->create();

        $manualLocations = DeviceLocation::manual()->get();

        expect($manualLocations)->toHaveCount(3);
    });

    it('can scope to automatic locations', function () {
        DeviceLocation::factory()->count(2)->create();
        DeviceLocation::factory()->manual()->count(3)->create();

        $automaticLocations = DeviceLocation::automatic()->get();

        expect($automaticLocations)->toHaveCount(2);
    });
});

describe('DeviceOwnershipHistory Model', function () {
    it('can be created with factory', function () {
        $history = DeviceOwnershipHistory::factory()->create();

        expect($history)->toBeInstanceOf(DeviceOwnershipHistory::class)
            ->and($history->transfer_reason)->toBeString()
            ->and($history->transferred_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('can be created as initial ownership', function () {
        $history = DeviceOwnershipHistory::factory()->initial()->create();

        expect($history->from_user_id)->toBeNull()
            ->and($history->transfer_reason)->toBe('Initial registration');
    });

    it('belongs to a device', function () {
        $device = Device::factory()->create();
        $history = DeviceOwnershipHistory::factory()->forDevice($device)->create();

        expect($history->device)->toBeInstanceOf(Device::class)
            ->and($history->device->id)->toBe($device->id);
    });

    it('has from and to user relationships', function () {
        $fromUser = User::factory()->create();
        $toUser = User::factory()->create();

        $history = DeviceOwnershipHistory::factory()
            ->fromUser($fromUser)
            ->toUser($toUser)
            ->create();

        expect($history->fromUser)->toBeInstanceOf(User::class)
            ->and($history->fromUser->id)->toBe($fromUser->id)
            ->and($history->toUser)->toBeInstanceOf(User::class)
            ->and($history->toUser->id)->toBe($toUser->id);
    });

    it('can scope to a specific device', function () {
        $device = Device::factory()->create();
        DeviceOwnershipHistory::factory()->forDevice($device)->count(2)->create();
        DeviceOwnershipHistory::factory()->count(3)->create();

        $deviceHistory = DeviceOwnershipHistory::forDevice($device->id)->get();

        expect($deviceHistory)->toHaveCount(2);
    });
});
