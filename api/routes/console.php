<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('invoices:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shifts:close-expired')->everyMinute()->withoutOverlapping(5);
