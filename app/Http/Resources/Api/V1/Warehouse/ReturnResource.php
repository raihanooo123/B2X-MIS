<?php

namespace App\Http\Resources\Api\V1\Warehouse;

use App\Domain\Returns\FaultReports;
use App\Models\Attachment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rma;
use App\Models\RmaLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A return for the staff Returns screen (05.4 §7.3, §13.5): what to expect,
 * what has arrived, the customer's proof of sending and the refund
 * deadline. Staff-only; no cost figures (CLAUDE.md invariant 9).
 *
 * Rejected proof is kept as evidence (05.4 §13.5). A file is shown as
 * rejected when its id is at or below the `last_proof_attachment_id` of the
 * latest `rma.proof_rejected` audit entry for the return — the audit log is
 * the record; there is no column for it.
 *
 * @property Rma $resource
 */
class ReturnResource extends JsonResource
{
    public function __construct(Rma $rma)
    {
        parent::__construct($rma);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rma = $this->resource;
        $order = Order::query()->find($rma->order_id, ['id', 'order_number', 'user_id', 'guest_email']);
        $user = $request->user();
        $refund = $rma->refund_payment_id === null ? null : Payment::query()->find($rma->refund_payment_id, ['id', 'gateway', 'status']);
        $can = fn (string $ability): bool => $user instanceof User && Gate::forUser($user)->allows($ability, $rma);
        $lastRejection = DB::table('audit_log')
            ->where('action', 'rma.proof_rejected')
            ->where('subject_type', 'rma')
            ->where('subject_id', $rma->id)
            ->orderByDesc('id')
            ->value('before');
        $decoded = is_string($lastRejection) ? json_decode($lastRejection, true) : null;
        $rejectedUpTo = is_array($decoded) && is_int($decoded['last_proof_attachment_id'] ?? null) ? $decoded['last_proof_attachment_id'] : 0;
        $replacement = $rma->replacement_order_id === null ? null : Order::query()->where('id', $rma->replacement_order_id)->first(['id', 'order_number', 'status']);
        $remedy = json_decode($rma->internal_note ?? '{}', true);

        return [
            'id' => $rma->public_id,
            'rma_number' => $rma->rma_number,
            'status' => $rma->status,
            'return_reason' => $rma->return_reason,
            'reason_detail' => $rma->reason_detail,
            'is_cancellation' => $rma->return_reason === 'consumer_cancellation',
            // 05.4 §13.4: faulty goods within 30 days of possession are refunded in full.
            'within_reject_period' => $rma->return_reason !== 'consumer_cancellation' && FaultReports::withinRejectPeriod($rma),
            'resolution_type' => $rma->resolution_type,
            // 05.4 §14: the zero-value order sending the replacement, once created.
            'replacement_order' => $replacement === null ? null : ['order_number' => $replacement->order_number, 'status' => $replacement->status],
            'remedy_record' => json_decode($rma->internal_note ?? '{}', true),
            'refund' => [
                'net_minor' => $rma->refund_net_minor,
                'tax_minor' => $rma->refund_tax_minor,
                'delivery_net_minor' => $rma->delivery_refund_net_minor,
                'delivery_tax_minor' => $rma->delivery_refund_tax_minor,
                'gross_minor' => $rma->refund_gross_minor,
                'method' => $refund === null ? null : ($refund->gateway === 'stripe' ? 'card' : 'bank_transfer'),
                'status' => $refund?->status,
            ],
            'order_number' => $order?->order_number,
            'customer' => $order->guest_email ?? ($order?->user_id === null ? null : User::query()->whereKey($order->user_id)->value('email')),
            'return_method' => $rma->return_method,
            'return_by_date' => $rma->return_by_date?->toDateString(),
            'goods_sent_at' => $rma->goods_sent_at?->toIso8601ZuluString(),
            'received_at' => $rma->received_at?->toIso8601ZuluString(),
            'refund_due_on' => $rma->refund_due_on?->toDateString(),
            'proof' => array_values(Attachment::query()
                ->where('attachable_type', 'rma')
                ->where('attachable_id', $rma->id)
                ->orderBy('id')
                ->get()
                ->map(fn (Attachment $a) => [
                    'id' => $a->public_id,
                    'name' => $a->original_name ?? 'Proof of sending',
                    'mime_type' => $a->mime_type,
                    'uploaded_at' => CarbonImmutable::parse((string) $a->getAttribute('created_at'))->toIso8601ZuluString(),
                    'rejected' => $a->id <= $rejectedUpTo,
                    'url' => route('warehouse.returns.proof', ['rma' => $rma->public_id, 'attachment' => $a->public_id]),
                ])
                ->all()),
            'lines' => array_values($rma->lines()->orderBy('line_no')->get()->map(fn (RmaLine $l) => [
                'line_no' => $l->line_no,
                'sku_code' => $l->sku_code_snapshot,
                'name' => $l->name_snapshot,
                'requested_base_qty' => $l->requested_base_qty,
                'received_base_qty' => $l->received_base_qty,
                'restocked_base_qty' => $l->restocked_base_qty,
                'quarantined_base_qty' => $l->quarantined_base_qty,
                'written_off_base_qty' => $l->written_off_base_qty,
                'disposition' => $l->disposition,
                'disposition_reason' => $l->disposition_reason,
                'diminished_value_minor' => $l->diminished_value_minor,
                'diminished_value_reason' => $l->diminished_value_reason,
                'batch_recovered' => $l->batch_id !== null,
                'line_refund_net_minor' => $l->line_refund_net_minor,
                'line_refund_tax_minor' => $l->line_refund_tax_minor,
            ])->all()),
            'can_receive' => $can('receive') && ($rma->status === 'awaiting_goods' || ($rma->status === 'resolved' && $rma->resolution_type === 'credit_note' && $rma->goods_sent_at !== null && $rma->received_at === null)) && $rma->approved_at !== null,
            'can_reject_proof' => $can('rejectProof') && $rma->status === 'awaiting_goods' && $rma->goods_sent_at !== null,
            'can_inspect' => $can('inspect') && ($rma->status === 'received' || ($rma->status === 'resolved' && $rma->resolution_type === 'credit_note' && $rma->goods_sent_at !== null && $rma->received_at !== null && $rma->inspected_at === null)),
            'can_resolve' => $can('resolve') && $rma->credit_note_id === null && (($rma->status === 'resolved' && in_array($rma->resolution_type, ['repair', 'replacement'], true)) || $rma->status === 'inspected'
                || ($rma->status === 'awaiting_goods' && $rma->goods_sent_at !== null && $rma->return_reason === 'consumer_cancellation')),
            'can_review' => $can('review') && $rma->status === 'requested',
            // 05.4 §14.2 R7 (Q-R2): mirrors ReplacementOrders::createAdvance(), which is the authority.
            'can_advance_replacement' => $can('resolve') && $rma->company_id === null && $rma->replacement_order_id === null
                && $rma->return_reason !== 'consumer_cancellation' && $rma->approved_at !== null
                && in_array($rma->status, ['approved', 'awaiting_goods', 'received'], true)
                && ! FaultReports::withinRejectPeriod($rma)
                && is_array($remedy) && ($remedy['customer_choice'] ?? null) === 'replacement',
            'can_record_bank_refund' => $can('recordRefund') && $refund !== null
                && ($refund->status === 'failed' || ($refund->gateway === 'bacs' && $refund->status === 'pending')),
        ];
    }
}
