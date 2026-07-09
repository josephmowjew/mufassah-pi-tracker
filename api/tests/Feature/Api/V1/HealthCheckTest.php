<?php

use function Pest\Laravel\getJson;

describe('API Health Check', function () {
    it('returns successful health check response', function () {
        $response = getJson(apiUrl('health'));

        $response
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'version',
                'timestamp',
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Mufasah Device Tracking API is running',
                'version' => '1.0.0',
            ]);
    });

    it('health check data contains valid timestamp', function () {
        $response = getJson(apiUrl('health'));

        $timestamp = $response->json('timestamp');

        expect($timestamp)->not->toBeEmpty();

        $parsedTimestamp = \Carbon\Carbon::parse($timestamp);
        expect($parsedTimestamp->isValid())->toBeTrue();
    });
});
