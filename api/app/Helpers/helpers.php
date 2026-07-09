<?php

use Illuminate\Support\Str;

if (!function_exists('generate_device_code')) {
    /**
     * Generate a unique device code in format MUF-XXXXXXXX
     */
    function generate_device_code(): string
    {
        return 'MUF-' . strtoupper(Str::random(8));
    }
}

if (!function_exists('generate_report_number')) {
    /**
     * Generate a unique theft report number in format TR-YYYYMMDD-XXXX
     */
    function generate_report_number(): string
    {
        return 'TR-' . date('Ymd') . '-' . strtoupper(Str::random(4));
    }
}

if (!function_exists('generate_claim_number')) {
    /**
     * Generate a unique warranty claim number in format WC-YYYYMMDD-XXXX
     */
    function generate_claim_number(): string
    {
        return 'WC-' . date('Ymd') . '-' . strtoupper(Str::random(4));
    }
}

if (!function_exists('format_phone_number')) {
    /**
     * Format phone number to Malawi standard (+265XXXXXXXXX)
     */
    function format_phone_number(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // If starts with 0, replace with 265
        if (str_starts_with($phone, '0')) {
            $phone = '265' . substr($phone, 1);
        }

        // If doesn't start with 265, prepend it
        if (!str_starts_with($phone, '265')) {
            $phone = '265' . $phone;
        }

        return '+' . $phone;
    }
}

if (!function_exists('calculate_distance')) {
    /**
     * Calculate distance between two GPS coordinates using Haversine formula
     *
     * @return float Distance in meters
     */
    function calculate_distance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371000; // Earth's radius in meters

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}

if (!function_exists('is_within_geofence')) {
    /**
     * Check if a point is within a circular geofence
     */
    function is_within_geofence(
        float $pointLat,
        float $pointLon,
        float $centerLat,
        float $centerLon,
        float $radiusMeters
    ): bool {
        $distance = calculate_distance($pointLat, $pointLon, $centerLat, $centerLon);
        return $distance <= $radiusMeters;
    }
}
