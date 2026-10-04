<?php

namespace App\Http\Resources\Api\V1\Warehouse;

use App\Models\Attachment;
use App\Models\Order;
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
        $lastRejection = DB::table('audit_log')
            ->where('action', 'rma.proof_rejected')
            ->where('subject_type', 'rma')
            ->where('subject_id', $rma->id)
            ->orderByDesc('id')
            ->value('before');
        $decoded = is_string($lastRejection) ? json_decode($lastRejection, true) : null;
        $rejectedUpTo = is_array($decoded) && is_int($decoded['last_proof_attachment_id'] ?? null) ? $decoded['last_proof_attachment_id'] : 0;

        return [
            'id' => $rma->public_id,
            'rma_number' => $rma->rma_number,
            'status' => $rma->status,
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
            ])->all()),
            'can_receive' => $user instanceof User && Gate::forUser($user)->allows('receive', $rma) && $rma->status === 'awaiting_goods',
            'can_reject_proof' => $user instanceof User && Gate::forUser($user)->allows('rejectProof', $rma) && $rma->status === 'awaiting_goods' && $rma->goods_sent_at !== null,
        ];
    }
}
