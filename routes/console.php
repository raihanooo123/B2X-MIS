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

// 05.4 §13.5, §7.6 — consumer returns: refund deadline alerts, then the not-received sweep.
Schedule::command('returns:refund-due-alerts')->dailyAt('07:30')->timezone('Europe/London')->withoutOverlapping();
Schedule::command('returns:sweep-not-received')->dailyAt('02:30')->timezone('Europe/London')->withoutOverlapping();

// 02 §29.4 — the storefront price sort key: time boundaries every 5 minutes, a full rebuild nightly.
Schedule::command('storefront:refresh-price-projection --stale')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('storefront:refresh-price-projection')->dailyAt('03:45')->timezone('Europe/London')->withoutOverlapping();

// 05.6 §7A.1, §7A.5 — collection slots from each location's weekly pattern; unpaid pay-at-collection orders expire.
Schedule::command('collection:generate-slots')->dailyAt('00:15')->timezone('Europe/London')->withoutOverlapping();
Schedule::command('collection:expire-unpaid')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// 05.2 §18.1–§18.2 — the approval/payment reaper; daily approval reminders; nightly debt suspension; hourly drift check.
Schedule::command('credit:expire-approvals')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('credit:approval-reminders')->dailyAt('08:00')->timezone('Europe/London')->withoutOverlapping();
Schedule::command('credit:suspend-overdue')->dailyAt('02:15')->timezone('Europe/London')->withoutOverlapping();
Schedule::command('credit:reconcile')->hourly()->withoutOverlapping()->onOneServer();
