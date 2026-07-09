<?php

namespace App\Enums;

enum AlertType: string
{
    case GeofenceViolation = 'geofence_violation';
    case BatteryLow = 'battery_low';
    case DeviceOffline = 'device_offline';
    case TamperDetected = 'tamper_detected';
    case TheftReported = 'theft_reported';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::GeofenceViolation => 'Geofence Violation',
            self::BatteryLow => 'Battery Low',
            self::DeviceOffline => 'Device Offline',
            self::TamperDetected => 'Tamper Detected',
            self::TheftReported => 'Theft Reported',
            self::System => 'System Alert',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::GeofenceViolation => 'orange',
            self::BatteryLow => 'yellow',
            self::DeviceOffline => 'gray',
            self::TamperDetected => 'red',
            self::TheftReported => 'red',
            self::System => 'blue',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
