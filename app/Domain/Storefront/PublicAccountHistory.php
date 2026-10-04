<?php

namespace App\Domain\Storefront;

use App\Models\Attachment;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;

/** 05.15 §6.4: one owner, 20 rows, newest first, no OFFSET or total count. */
final class PublicAccountHistory
{
    public const PAGE_SIZE = 20;

    public function ordersQuery(User $user): Builder
    {
        return DB::table('orders')->whereNull('company_id')->where('user_id', $user->id)->whereNotNull('placed_at');
    }

    /** @param array{at: string, id: int}|null $cursor
     * @return array{items: list<array<string, mixed>>, next: string|null}
     */
    public function orders(User $user, ?array $cursor): array
    {
        $query = $this->ordersQuery($user);
        if ($cursor !== null) {
            $query->whereRaw('(placed_at, id) < (?, ?)', [$cursor['at'], $cursor['id']]);
        }
        $rows = $query->orderByDesc('placed_at')->orderByDesc('id')->limit(self::PAGE_SIZE + 1)
            ->get(['id', 'public_id', 'order_number', 'placed_at', 'status', 'payment_status', 'total_gross_minor']);
        $last = $rows->get(self::PAGE_SIZE - 1);
        $next = $rows->count() > self::PAGE_SIZE && $last !== null ? $this->next($user, 'orders', $last, 'placed_at') : null;

        return ['items' => array_values($rows->take(self::PAGE_SIZE)->map(fn (stdClass $r) => [
            'id' => $r->public_id, 'order_number' => $r->order_number,
            'placed_on' => DisplayTime::format(CarbonImmutable::parse($r->placed_at)),
            'status' => $r->status, 'payment_status' => $r->payment_status, 'total_gross_minor' => (int) $r->total_gross_minor,
            'url' => route('orders.confirmation', $r->public_id),
        ])->values()->all()), 'next' => $next];
    }

    /** @param array{at: string, id: int}|null $cursor
     * @return array{items: list<array<string, mixed>>, next: string|null}
     */
    public function receipts(User $user, ?array $cursor): array
    {
        $query = DB::table('invoices')->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->whereNull('invoices.company_id')->whereNull('orders.company_id')->where('orders.user_id', $user->id)->whereNotNull('orders.placed_at')
            ->where('invoices.status', '<>', 'void')->whereNotNull('invoices.issued_at');
        if ($cursor !== null) {
            $query->whereRaw('(invoices.issued_at, invoices.id) < (?, ?)', [$cursor['at'], $cursor['id']]);
        }
        $rows = $query->orderByDesc('invoices.issued_at')->orderByDesc('invoices.id')->limit(self::PAGE_SIZE + 1)
            ->get(['invoices.id', 'invoices.public_id', 'invoices.invoice_number', 'invoices.issued_at', 'invoices.status', 'invoices.total_gross_minor', 'orders.public_id as order_public_id', 'orders.order_number']);
        $last = $rows->get(self::PAGE_SIZE - 1);
        $next = $rows->count() > self::PAGE_SIZE && $last !== null ? $this->next($user, 'receipts', $last, 'issued_at') : null;
        $files = Attachment::query()->where('attachable_type', 'invoice')->whereIn('attachable_id', $rows->take(self::PAGE_SIZE)->pluck('id'))
            ->where('is_customer_visible', true)->where('mime_type', 'application/pdf')->orderBy('id')->get()->groupBy('attachable_id');

        return ['items' => array_values($rows->take(self::PAGE_SIZE)->map(function (stdClass $r) use ($files): array {
            $pdf = $files->get($r->id)?->first();

            return [
                'id' => $r->public_id, 'receipt_number' => $r->invoice_number,
                'issued_on' => DisplayTime::format(CarbonImmutable::parse($r->issued_at)),
                'status' => $r->status, 'total_gross_minor' => (int) $r->total_gross_minor,
                'order_number' => $r->order_number, 'order_url' => route('orders.confirmation', $r->order_public_id),
                'download_url' => $pdf !== null && Storage::disk($pdf->disk)->exists($pdf->path) ? route('account.receipts.download', $r->public_id) : null,
            ];
        })->values()->all()), 'next' => $next];
    }

    private function next(User $user, string $kind, stdClass $row, string $column): string
    {
        return Crypt::encryptString(json_encode(['kind' => $kind, 'user' => $user->id, 'at' => $row->{$column}, 'id' => (int) $row->id], JSON_THROW_ON_ERROR));
    }
}
