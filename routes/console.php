<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 05.13 §5.4: the offline breached-password list is refreshed quarterly.
Schedule::command('auth:refresh-breached-passwords')->quarterly()->withoutOverlapping()->runInBackground();

// 05.12 — daily invoice reminders (05.2 §11) and log retention (07 §7.2).
Schedule::command('notifications:invoice-reminders')->dailyAt('08:00')->timezone('Europe/London')->withoutOverlapping();
Schedule::command('notifications:prune-log')->dailyAt('03:30')->timezone('Europe/London')->withoutOverlapping();
