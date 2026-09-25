<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/warehouse/receipts/{id}/lines (06 §8) — one receipt entry,
 * 05.5 §4.2 steps 2–6. Idempotency-Key required (06 §6): it becomes the
 * line's `client_token` (02 §23.2).
 *
 * Quantity is in packs (`pack_qty` of `pack_code`); the base quantity is
 * computed server-side from the pack. PO lines have no public_id, so one
 * is addressed by its PO number and line number, as on the PO itself. The
 * tracking rules (batch code, expiry, serials) depend on the SKU and are
 * checked by GoodsInService, where the SKU is known.
 */
class ReceiveGoodsRequest extends FormRequest
{
    /** A pallet of serial-tracked units is scanned, not typed; this bounds a pasted manifest. */
    public const MAX_SERIALS = 5000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchase_order_line' => ['sometimes', 'nullable', 'array:po_number,line_no'],
            'purchase_order_line.po_number' => ['required_with:purchase_order_line', 'string', 'max:64'],
            'purchase_order_line.line_no' => ['required_with:purchase_order_line', 'integer', 'min:1', 'max:32767'],
            'sku_id' => ['required', 'string', 'ulid'],
            'pack_code' => ['required', 'string', 'max:64'],
            'pack_qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'batch_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'serials' => ['sometimes', 'array', 'max:'.self::MAX_SERIALS],
            'serials.*' => ['string', 'max:128'],
            'bin_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'unit_cost_e4' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000000'],
            'confirm_expiry' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{po_number: string, line_no: int}|null
     */
    public function purchaseOrderLine(): ?array
    {
        $line = $this->validated('purchase_order_line');
        if (! is_array($line)) {
            return null;
        }

        return ['po_number' => trim((string) $line['po_number']), 'line_no' => (int) $line['line_no']];
    }

    public function skuPublicId(): string
    {
        return (string) $this->validated('sku_id');
    }

    public function packCode(): string
    {
        return (string) $this->validated('pack_code');
    }

    public function packQty(): int
    {
        return (int) $this->validated('pack_qty');
    }

    public function batchCode(): ?string
    {
        $code = $this->validated('batch_code');

        return $code === null ? null : (string) $code;
    }

    public function expiresOn(): ?CarbonImmutable
    {
        $date = $this->validated('expires_on');

        return $date === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', (string) $date);
    }

    /**
     * @return list<string>
     */
    public function serials(): array
    {
        $serials = $this->validated('serials', []);

        return is_array($serials) ? array_values(array_map(fn ($s) => (string) $s, $serials)) : [];
    }

    public function binCode(): ?string
    {
        $code = $this->validated('bin_code');

        return $code === null || trim((string) $code) === '' ? null : trim((string) $code);
    }

    public function unitCostE4(): ?int
    {
        $cost = $this->validated('unit_cost_e4');

        return $cost === null ? null : (int) $cost;
    }

    public function expiryConfirmed(): bool
    {
        return (bool) $this->validated('confirm_expiry', false);
    }

    public function clientToken(): string
    {
        return (string) $this->header('Idempotency-Key', '');
    }
}
