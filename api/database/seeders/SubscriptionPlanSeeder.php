<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'description' => 'Essential tracking features for personal use',
                'price_monthly' => 5000.00,
                'price_yearly' => 50000.00,
                'currency' => 'MWK',
                'max_devices' => 2,
                'max_geofences_per_device' => 3,
                'location_history_days' => 7,
                'real_time_tracking' => true,
                'theft_alerts' => true,
                'geofence_alerts' => true,
                'priority_support' => false,
                'features' => ['Basic location tracking', 'Email alerts', 'Mobile app access'],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Standard',
                'slug' => 'standard',
                'description' => 'Advanced tracking with extended history',
                'price_monthly' => 10000.00,
                'price_yearly' => 100000.00,
                'currency' => 'MWK',
                'max_devices' => 5,
                'max_geofences_per_device' => 10,
                'location_history_days' => 30,
                'real_time_tracking' => true,
                'theft_alerts' => true,
                'geofence_alerts' => true,
                'priority_support' => false,
                'features' => ['Everything in Basic', '30-day history', 'SMS alerts', 'Multiple geofences'],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'description' => 'Full-featured tracking with priority support',
                'price_monthly' => 20000.00,
                'price_yearly' => 200000.00,
                'currency' => 'MWK',
                'max_devices' => 20,
                'max_geofences_per_device' => -1,
                'location_history_days' => 365,
                'real_time_tracking' => true,
                'theft_alerts' => true,
                'geofence_alerts' => true,
                'priority_support' => true,
                'features' => ['Everything in Standard', '1-year history', 'Unlimited geofences', '24/7 priority support', 'API access'],
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Custom solution for businesses',
                'price_monthly' => 50000.00,
                'price_yearly' => 500000.00,
                'currency' => 'MWK',
                'max_devices' => -1,
                'max_geofences_per_device' => -1,
                'location_history_days' => 730,
                'real_time_tracking' => true,
                'theft_alerts' => true,
                'geofence_alerts' => true,
                'priority_support' => true,
                'features' => ['Everything in Premium', '2-year history', 'Unlimited devices', 'Dedicated account manager', 'Custom integrations'],
                'is_active' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
