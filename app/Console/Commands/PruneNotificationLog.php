<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use Illuminate\Console\Command;

/**
 * 07 §7.2 — `notification_log` is kept 2 years (05.12 §14), then deleted,
 * for every category and subject. Consent records live in
 * `notification_preferences` and are not touched here.
 */
class PruneNotificationLog extends Command
{
    protected $signature = 'notifications:prune-log';

    protected $description = 'Delete notification_log rows older than the 2-year retention period';

    public const RETENTION_YEARS = 2;

    public function handle(): int
    {
        $deleted = NotificationLog::query()->where('queued_at', '<', now()->subYears(self::RETENTION_YEARS))->delete();

        $this->info("Deleted {$deleted} notification log row(s).");

        return self::SUCCESS;
    }
}
