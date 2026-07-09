<?php

namespace Database\Seeders;

use App\Enums\DeviceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TheftReportStatus;
use App\Models\Brand;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\DeviceLocation;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\IotModule;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TheftReport;
use App\Models\TheftReportUpdate;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    // Malawi coordinates (bounding box for realistic locations)
    private const LAT_MIN = -17.13;

    private const LAT_MAX = -9.37;

    private const LON_MIN = 32.67;

    private const LON_MAX = 35.92;

    // Major cities for more realistic data
    private array $cities = [
        'Lilongwe' => ['lat' => -13.9626, 'lon' => 33.7741],
        'Blantyre' => ['lat' => -15.7861, 'lon' => 35.0058],
        'Mzuzu' => ['lat' => -11.4656, 'lon' => 34.0207],
        'Zomba' => ['lat' => -15.3833, 'lon' => 35.3333],
        'Kasungu' => ['lat' => -13.0333, 'lon' => 33.4833],
    ];

    public function run(): void
    {
        $this->command->info('Seeding test data...');

        // Get existing data
        $categories = DeviceCategory::all();
        $plans = SubscriptionPlan::all();
        $customerRole = \Spatie\Permission\Models\Role::findByName('customer', 'api');

        // Create additional customers
        $this->command->info('Creating 20 customer users...');
        $customers = User::factory(20)->create([
            'email_verified_at' => now(),
        ])->each(fn ($user) => $user->assignRole($customerRole));

        // Add existing customers
        $allCustomers = User::role('customer')->get();

        // Create IoT modules
        $this->command->info('Creating 30 IoT modules...');
        $iotModules = IotModule::factory(30)->create();

        // Create devices for each customer with proper category matching
        $this->command->info('Creating devices for each customer...');
        $allDevices = collect();

        // Pre-load brands and models for performance
        $brandsByCategory = Brand::with('productModels')->get()->groupBy('category_id');

        foreach ($allCustomers as $customer) {
            $deviceCount = fake()->numberBetween(1, 5);

            $customerDevices = collect();

            for ($i = 0; $i < $deviceCount; $i++) {
                // Pick a random category
                $category = $categories->random();

                // Try to get brand and model from product catalog
                $brandId = null;
                $productModelId = null;
                $brandName = null;
                $modelName = null;

                $categoryBrands = $brandsByCategory->get($category->id);
                if ($categoryBrands && $categoryBrands->isNotEmpty()) {
                    // Pick a random brand from this category
                    $brand = $categoryBrands->random();
                    $brandId = $brand->id;
                    $brandName = $brand->name;

                    // Pick a random model from this brand
                    if ($brand->productModels->isNotEmpty()) {
                        $productModel = $brand->productModels->random();
                        $productModelId = $productModel->id;
                        $modelName = $productModel->name;
                    }
                }

                // Fallback to factory data if no catalog data found
                if (! $brandName || ! $modelName) {
                    $deviceData = \Database\Factories\DeviceFactory::getRandomDeviceForCategory($category->slug);
                    $brandName = $brandName ?? $deviceData['brand'];
                    $modelName = $modelName ?? $deviceData['model'];
                }

                $device = Device::factory()
                    ->for($customer, 'owner')
                    ->for($category, 'category')
                    ->create([
                        'brand' => $brandName,
                        'model' => $modelName,
                        'brand_id' => $brandId,
                        'product_model_id' => $productModelId,
                    ]);

                $customerDevices->push($device);
                $allDevices->push($device);
            }

            // Link some devices to IoT modules
            $customerDevices->random(min($deviceCount, 2))->each(function ($device) use (&$iotModules) {
                // Find an unassigned module (one not linked to any device)
                $assignedModuleIds = Device::whereNotNull('iot_module_id')->pluck('iot_module_id');
                $module = $iotModules->whereNotIn('id', $assignedModuleIds)->first();
                if ($module) {
                    $module->update(['status' => 'active']);
                    $device->update(['iot_module_id' => $module->id]);
                }
            });
        }

        // Create location history for devices
        $this->command->info('Creating location history for devices...');
        foreach ($allDevices as $device) {
            $this->createLocationHistory($device);
        }

        // Create geofences
        $this->command->info('Creating geofences...');
        foreach ($allDevices->random(min(30, $allDevices->count())) as $device) {
            $this->createGeofencesForDevice($device);
        }

        // Create subscriptions and payments
        $this->command->info('Creating subscriptions and payments...');
        foreach ($allCustomers->random(min(15, $allCustomers->count())) as $customer) {
            $this->createSubscriptionForUser($customer, $plans);
        }

        // Create theft reports
        $this->command->info('Creating theft reports...');
        $stolenDevices = $allDevices->random(min(8, $allDevices->count()));
        foreach ($stolenDevices as $device) {
            $this->createTheftReport($device);
        }

        // Create warranties and claims
        $this->command->info('Creating warranties and claims...');
        foreach ($allDevices as $device) {
            $this->createWarrantyForDevice($device);
        }

        // Create notifications
        $this->command->info('Creating notifications...');
        foreach ($allCustomers as $customer) {
            Notification::factory(fake()->numberBetween(3, 10))
                ->forUser($customer)
                ->create();
        }

        $this->command->info('Test data seeding completed!');
        $this->command->table(
            ['Entity', 'Count'],
            [
                ['Users (customers)', $allCustomers->count()],
                ['Devices', $allDevices->count()],
                ['IoT Modules', $iotModules->count()],
                ['Locations', DeviceLocation::count()],
                ['Geofences', Geofence::count()],
                ['Geofence Events', GeofenceEvent::count()],
                ['Subscriptions', Subscription::count()],
                ['Payments', Payment::count()],
                ['Theft Reports', TheftReport::count()],
                ['Warranties', Warranty::count()],
                ['Warranty Claims', WarrantyClaim::count()],
                ['Notifications', Notification::count()],
            ]
        );
    }

    private function createLocationHistory(Device $device): void
    {
        // Choose a random city as base
        $city = fake()->randomElement($this->cities);
        $locationCount = fake()->numberBetween(20, 100);

        $locations = collect();
        for ($i = 0; $i < $locationCount; $i++) {
            // Add some variance around the city
            $lat = $city['lat'] + fake()->randomFloat(4, -0.05, 0.05);
            $lon = $city['lon'] + fake()->randomFloat(4, -0.05, 0.05);

            $locations->push([
                'device_id' => $device->id,
                'iot_module_id' => $device->iot_module_id,
                'latitude' => $lat,
                'longitude' => $lon,
                'altitude' => fake()->optional(0.6)->randomFloat(2, 400, 1500),
                'accuracy' => fake()->randomFloat(2, 3, 30),
                'speed' => fake()->optional(0.5)->randomFloat(2, 0, 80),
                'heading' => fake()->optional(0.5)->randomFloat(2, 0, 360),
                'is_manual' => fake()->boolean(10),
                'recorded_at' => fake()->dateTimeBetween('-60 days', 'now'),
                'created_at' => now(),
            ]);
        }

        DeviceLocation::insert($locations->toArray());

        // Update device's last location
        $lastLocation = DeviceLocation::where('device_id', $device->id)
            ->orderBy('recorded_at', 'desc')
            ->first();

        if ($lastLocation) {
            $device->update([
                'last_location_id' => $lastLocation->id,
                'last_seen_at' => $lastLocation->recorded_at,
            ]);
        }
    }

    private function createGeofencesForDevice(Device $device): void
    {
        $geofenceCount = fake()->numberBetween(1, 3);

        for ($i = 0; $i < $geofenceCount; $i++) {
            // Get a location from the device's history to create a realistic geofence
            $location = DeviceLocation::where('device_id', $device->id)->inRandomOrder()->first();

            if (! $location) {
                continue;
            }

            $geofence = Geofence::factory()
                ->for($device)
                ->create([
                    'center_latitude' => $location->latitude,
                    'center_longitude' => $location->longitude,
                    'radius_meters' => fake()->randomElement([100, 200, 500, 1000, 2000]),
                ]);

            // Create some geofence events using existing locations
            $eventCount = fake()->numberBetween(0, 10);
            $deviceLocations = DeviceLocation::where('device_id', $device->id)->inRandomOrder()->limit($eventCount)->get();

            foreach ($deviceLocations as $eventLocation) {
                GeofenceEvent::factory()
                    ->for($geofence)
                    ->for($device)
                    ->for($eventLocation, 'location')
                    ->create();
            }
        }
    }

    private function createSubscriptionForUser(User $user, $plans): void
    {
        $plan = $plans->random();

        $subscription = Subscription::factory()
            ->for($user)
            ->for($plan, 'plan')
            ->create([
                'status' => fake()->randomElement([
                    SubscriptionStatus::Active,
                    SubscriptionStatus::Active,
                    SubscriptionStatus::Active,
                    SubscriptionStatus::Expired,
                    SubscriptionStatus::Cancelled,
                ]),
            ]);

        // Create payments for the subscription
        $paymentCount = fake()->numberBetween(1, 6);
        for ($i = 0; $i < $paymentCount; $i++) {
            Payment::factory()
                ->for($user)
                ->for($subscription)
                ->create([
                    'amount' => $subscription->billing_cycle === 'monthly'
                        ? $plan->price_monthly
                        : $plan->price_yearly,
                    'status' => $i === 0
                        ? PaymentStatus::Completed
                        : fake()->randomElement([
                            PaymentStatus::Completed,
                            PaymentStatus::Completed,
                            PaymentStatus::Pending,
                            PaymentStatus::Failed,
                        ]),
                ]);
        }
    }

    private function createTheftReport(Device $device): void
    {
        $device->update(['status' => DeviceStatus::STOLEN]);

        $status = fake()->randomElement([
            TheftReportStatus::Reported,
            TheftReportStatus::Investigating,
            TheftReportStatus::Investigating,
            TheftReportStatus::Recovered,
            TheftReportStatus::Closed,
        ]);

        $report = TheftReport::factory()
            ->for($device)
            ->for($device->owner, 'reporter')
            ->create(['status' => $status]);

        // Add updates to the report
        $updateCount = fake()->numberBetween(1, 5);
        for ($i = 0; $i < $updateCount; $i++) {
            TheftReportUpdate::factory()
                ->for($report, 'theftReport')
                ->create();
        }

        // If recovered, update device status
        if ($status === TheftReportStatus::Recovered) {
            $device->update(['status' => DeviceStatus::RECOVERED]);
        }
    }

    private function createWarrantyForDevice(Device $device): void
    {
        $warranty = Warranty::factory()
            ->for($device)
            ->create();

        // Some warranties have claims
        if (fake()->boolean(30)) {
            WarrantyClaim::factory()
                ->for($warranty)
                ->create();
        }
    }
}
