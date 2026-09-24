<?php

return [
    'validity_hours' => (int) env('INVOICE_VALIDITY_HOURS', 24),
    'default_tax_rate' => (float) env('INVOICE_DEFAULT_TAX_RATE', 17.5),
];
