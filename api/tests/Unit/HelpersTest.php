<?php

describe('Helper Functions', function () {
    describe('generate_device_code', function () {
        it('generates a code with MUF prefix', function () {
            $code = generate_device_code();

            expect($code)->toStartWith('MUF-');
        });

        it('generates an 8-character alphanumeric suffix', function () {
            $code = generate_device_code();
            $suffix = substr($code, 4);

            expect(strlen($suffix))->toBe(8)
                ->and($suffix)->toMatch('/^[A-Z0-9]+$/');
        });

        it('generates unique codes', function () {
            $codes = [];
            for ($i = 0; $i < 100; $i++) {
                $codes[] = generate_device_code();
            }

            expect(array_unique($codes))->toHaveCount(100);
        });
    });

    describe('generate_report_number', function () {
        it('generates a report number with TR prefix', function () {
            $number = generate_report_number();

            expect($number)->toStartWith('TR-');
        });

        it('includes current date in format YYYYMMDD', function () {
            $number = generate_report_number();
            $datepart = substr($number, 3, 8);

            expect($datepart)->toBe(date('Ymd'));
        });
    });

    describe('generate_claim_number', function () {
        it('generates a claim number with WC prefix', function () {
            $number = generate_claim_number();

            expect($number)->toStartWith('WC-');
        });

        it('includes current date in format YYYYMMDD', function () {
            $number = generate_claim_number();
            $datepart = substr($number, 3, 8);

            expect($datepart)->toBe(date('Ymd'));
        });
    });

    describe('format_phone_number', function () {
        it('formats Malawi phone numbers starting with 0', function () {
            $formatted = format_phone_number('0999123456');

            expect($formatted)->toBe('+265999123456');
        });

        it('formats phone numbers without country code', function () {
            $formatted = format_phone_number('999123456');

            expect($formatted)->toBe('+265999123456');
        });

        it('handles phone numbers already with 265 prefix', function () {
            $formatted = format_phone_number('265999123456');

            expect($formatted)->toBe('+265999123456');
        });

        it('removes non-numeric characters', function () {
            $formatted = format_phone_number('+265 999 123 456');

            expect($formatted)->toBe('+265999123456');
        });
    });

    describe('calculate_distance', function () {
        it('calculates distance between two points', function () {
            // Lilongwe to Blantyre (approximately 311km)
            $distance = calculate_distance(-13.9626, 33.7741, -15.7861, 35.0058);

            // Should be approximately 240km (240000 meters)
            expect($distance)->toBeGreaterThan(230000)
                ->and($distance)->toBeLessThan(250000);
        });

        it('returns zero for same coordinates', function () {
            $distance = calculate_distance(-13.9626, 33.7741, -13.9626, 33.7741);

            expect($distance)->toBe(0.0);
        });
    });

    describe('is_within_geofence', function () {
        it('returns true when point is inside geofence', function () {
            $centerLat = -13.9626;
            $centerLon = 33.7741;
            $pointLat = -13.9627;
            $pointLon = 33.7742;
            $radius = 500; // 500 meters

            $result = is_within_geofence($pointLat, $pointLon, $centerLat, $centerLon, $radius);

            expect($result)->toBeTrue();
        });

        it('returns false when point is outside geofence', function () {
            $centerLat = -13.9626;
            $centerLon = 33.7741;
            $pointLat = -14.0000;
            $pointLon = 34.0000;
            $radius = 500; // 500 meters

            $result = is_within_geofence($pointLat, $pointLon, $centerLat, $centerLon, $radius);

            expect($result)->toBeFalse();
        });
    });
});
