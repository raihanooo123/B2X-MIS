<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Documents\CreditNoteDocumentBuilder;
use App\Domain\Billing\Documents\InvoiceDocumentBuilder;
use App\Domain\Credit\CreditGate;
use App\Domain\Documents\DocumentRenders;
use App\Domain\Documents\PdfRenderFailed;
use App\Models\AccountStatement;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\DocumentRender;
use App\Models\Invoice;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 05.17 §2 — a trade company's invoices, credit notes and statements, for
 * its owners and approvers (CompanyPolicy::viewFinancialDocuments). Read
 * only. Lists are keyset paged on (issued_at, id) — the trade history
 * cursor indexes — 50 a page by default, 100 at most; dates are UK
 * calendar days. Outstanding is gross − cash paid − credited (05.2 §18).
 *
 * Detail shows the identity fixed at issue (the document's captured
 * payload) and allocations as they stand; the PDF is that payload, printed.
 * No cost, staff note, storage key or accounting provider id is returned.
 */
final class TradeDocuments
{
    public const PAGE_SIZE = 50;

    public const MAX_PAGE_SIZE = 100;

    public const SORTS = ['issued_desc' => 'desc', 'issued_asc' => 'asc'];

    /** filter value => invoice statuses */
    public const INVOICE_STATUS_GROUPS = [
        'unpaid' => ['issued', 'part_paid', 'overdue'],
        'paid' => ['paid'],
        'credited' => ['credited'],
        'void' => ['void'],
    ];

    public function __construct(private readonly DocumentRenders $renders) {}

    /** @return array<string, mixed> due and overdue totals for the invoices page */
    public function invoiceSummary(Company $company): array
    {
        $row = CreditGate::outstanding(Invoice::query()->where('company_id', $company->id))->toBase()
            ->selectRaw('count(*) AS n, coalesce(sum(total_gross_minor - paid_minor - credited_minor), 0) AS due,
                count(*) FILTER (WHERE due_at < now()) AS overdue_n,
                coalesce(sum(total_gross_minor - paid_minor - credited_minor) FILTER (WHERE due_at < now()), 0) AS overdue')
            ->first();

        return [
            'outstanding_count' => (int) ($row->n ?? 0),
            'outstanding_minor' => (int) ($row->due ?? 0),
            'overdue_count' => (int) ($row->overdue_n ?? 0),
            'overdue_minor' => (int) ($row->overdue ?? 0),
        ];
    }

    /**
     * @param  array{status: ?string, q: ?string, from: ?string, to: ?string, sort: string}  $filters
     * @param  array<string, int|string|null>|null  $after
     * @return array{rows: list<array<string, mixed>>, next: array<string, int|string>|null}
     */
    public function invoices(int $companyId, array $filters, ?array $after, int $perPage = self::PAGE_SIZE): array
    {
        $query = Invoice::query()->where('company_id', $companyId);
        if ($filters['status'] === 'overdue') {
            CreditGate::outstanding($query)->where('due_at', '<', now());
        } elseif ($filters['status'] !== null && isset(self::INVOICE_STATUS_GROUPS[$filters['status']])) {
            $query->whereIn('status', self::INVOICE_STATUS_GROUPS[$filters['status']]);
        }
        if ($filters['q'] !== null) {
            $query->where('invoice_number', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
        $this->dateRange($query, $filters);

        [$models, $next] = $this->keyset($query, $filters['sort'], $after, $perPage);

        $rows = [];
        foreach ($models as $invoice) {
            if ($invoice instanceof Invoice) {
                $rows[] = $this->invoiceRow($invoice);
            }
        }

        return ['rows' => $rows, 'next' => $next];
    }

    /**
     * @param  array{reason: ?string, q: ?string, from: ?string, to: ?string, sort: string}  $filters
     * @param  array<string, int|string|null>|null  $after
     * @return array{rows: list<array<string, mixed>>, next: array<string, int|string>|null}
     */
    public function creditNotes(int $companyId, array $filters, ?array $after, int $perPage = self::PAGE_SIZE): array
    {
        $query = CreditNote::query()->where('company_id', $companyId)->with('invoice:id,invoice_number');
        if ($filters['reason'] !== null && isset(CreditNoteDocumentBuilder::REASONS[$filters['reason']])) {
            $query->where('reason', $filters['reason']);
        }
        if ($filters['q'] !== null) {
            $query->where('credit_note_number', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
        $this->dateRange($query, $filters);

        [$models, $next] = $this->keyset($query, $filters['sort'], $after, $perPage);
        $page = ['rows' => [], 'next' => $next];
        foreach ($models as $note) {
            if ($note instanceof CreditNote) {
                $page['rows'][] = $this->creditNoteRow($note, 0);
            }
        }
        // One query for the page's allocations, not one per row.
        $allocated = DB::table('credit_note_allocations')
            ->whereIn('credit_note_id', CreditNote::query()->whereIn('public_id', array_column($page['rows'], 'id'))->select('id'))
            ->groupBy('credit_note_id')->selectRaw('credit_note_id, sum(amount_minor) AS n')->pluck('n', 'credit_note_id');
        $ids = CreditNote::query()->whereIn('public_id', array_column($page['rows'], 'id'))->pluck('id', 'public_id');
        foreach ($page['rows'] as &$row) {
            $sum = (int) ($allocated[$ids[$row['id']] ?? 0] ?? 0);
            $row['allocated_minor'] = $sum;
            $row['to_balance_minor'] = max(0, $row['total_gross_minor'] - $sum);
        }

        return $page;
    }

    /** @return array<string, mixed> */
    public function invoiceDetail(Invoice $invoice): array
    {
        $render = $this->renders->latest('invoice', $invoice->id);
        $payload = $render?->payload;
        // Lines and VAT come from the issued payload when captured; older
        // invoices show the same immutable order-line snapshots.
        if ($payload === null) {
            try {
                $payload = (new InvoiceDocumentBuilder)->build($invoice)->toArray();
                // Identity was not captured at issue: show no reconstructed seller/customer.
                $payload['seller'] = null;
                $payload['customer'] = null;
            } catch (Throwable) {
                $payload = null;
            }
        }

        $cash = DB::table('payment_allocations')->where('invoice_id', $invoice->id)->orderBy('allocated_at')->orderBy('id')
            ->get(['allocated_at', 'amount_minor']);
        $credits = DB::table('credit_note_allocations AS a')->join('credit_notes AS n', 'n.id', '=', 'a.credit_note_id')
            ->where('a.invoice_id', $invoice->id)->orderBy('a.allocated_at')->orderBy('a.id')
            ->get(['a.allocated_at', 'a.amount_minor', 'n.public_id', 'n.credit_note_number']);

        return [
            ...$this->invoiceRow($invoice),
            'kind' => $invoice->isReceipt() ? 'receipt' : 'vat_invoice',
            'payment_terms' => $invoice->payment_terms,
            'order' => $invoice->order === null ? null : ['id' => $invoice->order->public_id, 'number' => $invoice->order->order_number],
            'issued_identity' => $render === null ? null : [
                'seller' => $payload['seller'] ?? null,
                'customer' => $payload['customer'] ?? null,
                'delivery_address_lines' => $payload['delivery_address_lines'] ?? [],
            ],
            'lines' => $payload['lines'] ?? [],
            'carriage' => $payload['carriage'] ?? null,
            'vat_summary' => $payload['vat_summary'] ?? [],
            'totals' => [
                'subtotal_net_minor' => $invoice->subtotal_net_minor,
                'discount_net_minor' => $invoice->discount_net_minor,
                'shipping_net_minor' => $invoice->shipping_net_minor,
                'tax_minor' => $invoice->tax_minor,
                'total_gross_minor' => $invoice->total_gross_minor,
            ],
            'allocations' => [
                ...array_map(fn (object $a): array => [
                    'kind' => 'payment',
                    'at' => CarbonImmutable::parse((string) $a->allocated_at)->toIso8601ZuluString(),
                    'amount_minor' => (int) $a->amount_minor,
                    'credit_note' => null,
                ], $cash->all()),
                ...array_map(fn (object $a): array => [
                    'kind' => 'credit_note',
                    'at' => CarbonImmutable::parse((string) $a->allocated_at)->toIso8601ZuluString(),
                    'amount_minor' => (int) $a->amount_minor,
                    'credit_note' => ['id' => (string) $a->public_id, 'number' => (string) $a->credit_note_number],
                ], $credits->all()),
            ],
            'document' => $this->documentState('invoice', $invoice->id, 'trade.invoices.download', $invoice->public_id),
        ];
    }

    /** @return array<string, mixed> */
    public function creditNoteDetail(CreditNote $note): array
    {
        $note->loadMissing(['invoice:id,public_id,invoice_number,issued_at', 'order:id,public_id,order_number']);
        $allocations = DB::table('credit_note_allocations AS a')->join('invoices AS i', 'i.id', '=', 'a.invoice_id')
            ->where('a.credit_note_id', $note->id)->orderBy('a.allocated_at')->orderBy('a.id')
            ->get(['a.allocated_at', 'a.amount_minor', 'i.public_id', 'i.invoice_number']);
        $allocated = (int) $allocations->sum('amount_minor');

        return [
            ...$this->creditNoteRow($note, $allocated),
            'net_minor' => $note->subtotal_net_minor,
            'tax_minor' => $note->tax_minor,
            'original_invoice' => $note->invoice === null ? null : [
                'id' => $note->invoice->public_id,
                'number' => $note->invoice->invoice_number,
                'issued_at' => $note->invoice->issued_at->toIso8601ZuluString(),
            ],
            'order' => $note->order === null ? null : ['id' => $note->order->public_id, 'number' => $note->order->order_number],
            'allocations' => array_values($allocations->map(fn (object $a): array => [
                'at' => CarbonImmutable::parse((string) $a->allocated_at)->toIso8601ZuluString(),
                'amount_minor' => (int) $a->amount_minor,
                'invoice' => ['id' => (string) $a->public_id, 'number' => (string) $a->invoice_number],
            ])->all()),
            'document' => $this->documentState('credit_note', $note->id, 'trade.credit-notes.download', $note->public_id),
        ];
    }

    /**
     * @param  array<string, int|string|null>|null  $after
     * @return array{rows: list<array<string, mixed>>, next: array<string, int|string>|null}
     */
    public function statements(int $companyId, ?array $after): array
    {
        $query = AccountStatement::query()->where('company_id', $companyId)->with('requestedBy:id,first_name,last_name')
            ->orderByDesc('requested_at')->orderByDesc('id');
        if ($after !== null) {
            $query->whereRaw('(requested_at, id) < (?, ?)', [$after['v'], $after['id']]);
        }
        $rows = $query->limit(self::PAGE_SIZE + 1)->get();
        $more = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);
        $last = $rows->last();
        $renders = DocumentRender::query()->where('document_type', 'statement')->whereIn('source_id', $rows->pluck('id'))
            ->get(['source_id', 'status'])->keyBy('source_id');

        return [
            'rows' => array_values($rows->map(fn (AccountStatement $s): array => [
                ...$this->statementRow($s),
                'document_status' => $renders->get($s->id)->status ?? 'none',
            ])->all()),
            'next' => $more && $last !== null ? ['v' => (string) $last->getRawOriginal('requested_at'), 'id' => $last->id] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function statementDetail(AccountStatement $statement): array
    {
        $render = $this->renders->latest('statement', $statement->id);

        return [
            ...$this->statementRow($statement),
            'figures' => $render?->payload,
            'document' => $this->documentState('statement', $statement->id, 'trade.statements.download', $statement->public_id),
        ];
    }

    /**
     * The PDF's state for a source page: none, pending, rendering, ready
     * or failed, a customer-safe message, and the download link once ready.
     *
     * @return array<string, mixed>
     */
    public function documentState(string $type, int $sourceId, string $downloadRoute, string $sourcePublicId): array
    {
        $render = $this->renders->latest($type, $sourceId);

        return self::renderDto($render, $render?->status === DocumentRender::READY ? route($downloadRoute, $sourcePublicId) : null);
    }

    /** @return array<string, mixed> */
    public static function renderDto(?DocumentRender $render, ?string $downloadUrl): array
    {
        return [
            'id' => $render?->public_id,
            'status' => $render->status ?? 'none',
            'message' => match ($render?->status) {
                null => PdfRenderFailed::customerMessage(PdfRenderFailed::MISSING_SNAPSHOT),
                DocumentRender::FAILED => PdfRenderFailed::customerMessage($render->error_code),
                DocumentRender::PENDING, DocumentRender::RENDERING => 'Preparing the PDF…',
                default => null,
            },
            'download_url' => $downloadUrl,
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceRow(Invoice $i): array
    {
        $outstanding = in_array($i->status, CreditGate::UNPAID_STATUSES, true) ? max(0, $i->total_gross_minor - $i->paid_minor - $i->credited_minor) : 0;

        return [
            'id' => $i->public_id,
            'number' => $i->invoice_number,
            'status' => $i->status,
            'issued_at' => $i->issued_at->toIso8601ZuluString(),
            'due_at' => $i->due_at?->toIso8601ZuluString(),
            'overdue' => $outstanding > 0 && $i->due_at !== null && $i->due_at->isPast(),
            'total_gross_minor' => $i->total_gross_minor,
            'paid_minor' => $i->paid_minor,
            'credited_minor' => $i->credited_minor,
            'outstanding_minor' => $outstanding,
        ];
    }

    /** @return array<string, mixed> */
    private function creditNoteRow(CreditNote $n, int $allocated): array
    {
        return [
            'id' => $n->public_id,
            'number' => $n->credit_note_number,
            'status' => $n->status,
            'reason' => $n->reason,
            'reason_label' => CreditNoteDocumentBuilder::REASONS[$n->reason] ?? 'Other',
            'issued_at' => $n->issued_at->toIso8601ZuluString(),
            'invoice_number' => $n->invoice?->invoice_number,
            'total_gross_minor' => $n->total_gross_minor,
            'allocated_minor' => $allocated,
            'to_balance_minor' => max(0, $n->total_gross_minor - $allocated),
        ];
    }

    /** @return array<string, mixed> */
    private function statementRow(AccountStatement $s): array
    {
        $by = $s->requestedBy;

        return [
            'id' => $s->public_id,
            'from_on' => $s->from_on->toDateString(),
            'to_on' => $s->to_on->toDateString(),
            'cutoff_at' => $s->cutoff_at->toIso8601ZuluString(),
            'requested_at' => $s->requested_at->toIso8601ZuluString(),
            'requested_by' => $by === null ? null : trim("{$by->first_name} {$by->last_name}"),
        ];
    }

    /**
     * One keyset page of documents on (issued_at, id), and the next position.
     *
     * @param  Builder<Invoice>|Builder<CreditNote>  $query
     * @param  array<string, int|string|null>|null  $after
     * @return array{0: list<Invoice|CreditNote>, 1: array<string, int|string>|null}
     */
    private function keyset(Builder $query, string $sort, ?array $after, int $perPage): array
    {
        $perPage = max(1, min($perPage, self::MAX_PAGE_SIZE));
        $direction = self::SORTS[$sort] ?? 'desc';
        $query->orderBy('issued_at', $direction)->orderBy('id', $direction);
        if ($after !== null) {
            $operator = $direction === 'desc' ? '<' : '>';
            $query->whereRaw("(issued_at, id) {$operator} (?, ?)", [$after['v'], $after['id']]);
        }

        $rows = $query->limit($perPage + 1)->get();
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage);
        $last = $rows->last();

        return [
            array_values($rows->all()),
            $more && $last !== null ? ['v' => (string) $last->getRawOriginal('issued_at'), 'id' => (int) $last->getKey()] : null,
        ];
    }

    /**
     * @param  Builder<Invoice>|Builder<CreditNote>  $query
     * @param  array{from: ?string, to: ?string}  $filters
     */
    private function dateRange(Builder $query, array $filters): void
    {
        if ($filters['from'] !== null) {
            $query->where('issued_at', '>=', CarbonImmutable::parse($filters['from'], DisplayTime::zone())->startOfDay()->utc());
        }
        if ($filters['to'] !== null) {
            $query->where('issued_at', '<', CarbonImmutable::parse($filters['to'], DisplayTime::zone())->addDay()->startOfDay()->utc());
        }
    }
}
