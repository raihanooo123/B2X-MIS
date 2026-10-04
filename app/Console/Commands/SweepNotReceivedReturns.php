<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Notifications;
use App\Models\Rma;
use App\Support\DisplayTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 05.4 §7.6, §13.5 as amended 2026-10-04 — a return past its send-back date
 * (UK) with **neither proof of sending nor a receipt**, and not one we
 * collect, becomes `not_received`, with no refund; the customer and
 * accounts are told. A return with proof is skipped: its refund is owed
 * from the proof whether or not the parcel arrives (reg. 34(5)).
 *
 * Each row is re-checked under its lock, so a receipt or an upload landing
 * while the sweep runs is never overwritten. Served by `rmas_return_by_idx`.
 */
class SweepNotReceivedReturns extends Command
{
    protected $signature = 'returns:sweep-not-received';

    protected $description = 'Close returns past their send-back date with no proof and no receipt (05.4 §7.6)';

    public function handle(Notifications $notifications): int
    {
        $today = DisplayTime::local(now())->startOfDay()->toDateString();
        $closed = 0;

        $ids = Rma::query()
            ->where('status', 'awaiting_goods')
            ->where('return_by_date', '<', $today)
            ->whereNull('goods_sent_at')
            ->whereNull('received_at')
            ->where(fn ($q) => $q->whereNull('return_method')->orWhere('return_method', '<>', 'collection'))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        foreach ($ids as $id) {
            $swept = DB::transaction(function () use ($id): bool {
                $rma = Rma::query()->lockForUpdate()->find($id);
                if ($rma === null || $rma->status !== 'awaiting_goods' || $rma->goods_sent_at !== null || $rma->received_at !== null) {
                    return false;
                }
                $rma->status = 'not_received';
                $rma->setAttribute('refund_due_on', null);
                $rma->save();

                return true;
            });

            if ($swept) {
                $notifications->rmaNotReceived($id);
                $closed++;
            }
        }

        $this->info("Closed {$closed} return(s) not received by their send-back date.");

        return self::SUCCESS;
    }
}
