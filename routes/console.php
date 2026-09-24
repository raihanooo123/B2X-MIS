<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 05.13 §5.4: the offline breached-password list is refreshed quarterly.
Schedule::command('auth:refresh-breached-passwords')->quarterly()->withoutOverlapping()->runInBackground();
