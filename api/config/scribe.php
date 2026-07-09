<?php

use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

use function Knuckles\Scribe\Config\configureStrategy;

return [
    'title' => 'Mufasah Device Tracking API',

    'description' => 'API for the Mufasah Device Tracking System - A comprehensive platform for tracking and protecting electronic devices with IoT integration, geofencing, theft reporting, and subscription management.',

    'intro_text' => <<<'INTRO'
        Welcome to the Mufasah Device Tracking API documentation.

        This API provides endpoints for:
        - **Authentication** - User registration, login, and token management
        - **Device Management** - Register and manage tracked devices
        - **Location Tracking** - Real-time and historical location data
        - **Geofencing** - Create virtual boundaries with entry/exit alerts
        - **Theft Reporting** - Report and track stolen devices
        - **Subscriptions** - Manage subscription plans and payments
        - **Warranties** - Device warranty registration and claims
        - **Notifications** - Multi-channel notification preferences

        <aside>
        All authenticated endpoints require a Bearer token in the Authorization header.
        Get your token by logging in via the <code>/api/v1/auth/login</code> endpoint.
        </aside>
    INTRO,

    'base_url' => config('app.url'),

    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/v1/*'],
                'domains' => ['*'],
            ],
            'include' => [],
            'exclude' => [
                'api/v1/webhooks/*',
                'api/v1/payments/webhook',
            ],
        ],
    ],

    'type' => 'static',

    'theme' => 'default',

    'static' => [
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        'add_routes' => true,
        'docs_url' => '/docs',
        'assets_directory' => null,
        'middleware' => [],
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        'enabled' => true,
        'base_url' => null,
        'use_csrf' => false,
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    'auth' => [
        'enabled' => true,
        'default' => true,
        'in' => AuthIn::BEARER->value,
        'name' => 'Authorization',
        'use_value' => env('SCRIBE_AUTH_KEY'),
        'placeholder' => '{YOUR_AUTH_TOKEN}',
        'extra_info' => <<<'INFO'
            To authenticate, include the Bearer token in the Authorization header:
            ```
            Authorization: Bearer {YOUR_AUTH_TOKEN}
            ```

            Get your token by:
            1. Register via `POST /api/v1/auth/register`
            2. Or login via `POST /api/v1/auth/login`

            The token is returned in the `data.token` field of the response.
        INFO,
    ],

    'example_languages' => [
        'bash',
        'javascript',
        'php',
    ],

    'postman' => [
        'enabled' => true,
        'overrides' => [
            'info.version' => '1.0.0',
        ],
    ],

    'openapi' => [
        'enabled' => true,
        'overrides' => [
            'info.version' => '1.0.0',
        ],
        'generators' => [],
    ],

    'groups' => [
        'default' => 'Other Endpoints',
        'order' => [
            'Health Check',
            'Authentication',
            'User Profile',
            'Devices',
            'Device Locations',
            'Geofences',
            'Theft Reports',
            'Subscriptions',
            'Subscription Plans',
            'Payments',
            'Warranties',
            'Warranty Claims',
            'Notifications',
            'IoT Modules',
            'Device Categories',
            'Admin Dashboard',
            'Admin User Management',
            'Admin Reports',
        ],
    ],

    'logo' => false,

    'last_updated' => 'Last updated: {date:F j, Y}',

    'examples' => [
        'faker_seed' => 1234,
        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        'responses' => configureStrategy(
            Defaults::RESPONSES_STRATEGIES,
            Strategies\Responses\ResponseCalls::withSettings(
                only: ['GET *'],
                config: [
                    'app.debug' => false,
                ]
            )
        ),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [
        'serializer' => null,
    ],
];
