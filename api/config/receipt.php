<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Receipt Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for receipt generation and printing
    |
    */

    // Company information that appears on receipts
    'address' => env('RECEIPT_COMPANY_ADDRESS', '123 Business Street'),
    'phone' => env('RECEIPT_COMPANY_PHONE', '+1 234 567 890'),
    'email' => env('RECEIPT_COMPANY_EMAIL', 'info@mufassah.com'),
    'tax_id' => env('RECEIPT_TAX_ID', 'TAX-123456789'),
    'logo' => env('RECEIPT_LOGO_URL'),

    // Receipt formatting
    'currency' => env('RECEIPT_CURRENCY', 'MWK'),
    'return_days' => env('RECEIPT_RETURN_DAYS', 7),
    'vat_rate' => env('RECEIPT_VAT_RATE', 17.5), // Malawi VAT rate as percentage

    // Default formats
    'default_format' => env('RECEIPT_DEFAULT_FORMAT', 'a4'), // a4 or thermal
    'default_output' => env('RECEIPT_DEFAULT_OUTPUT', 'html'), // html or pdf

    // Thermal printer settings
    'thermal' => [
        'width' => env('RECEIPT_THERMAL_WIDTH', 80), // 58 or 80 mm
    ],
];
