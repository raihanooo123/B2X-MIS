<?php

namespace App\Domain\Billing\Documents;

use App\Domain\Billing\SellerDetails;
use App\Filament\Support\MoneyFormatter;
use App\Models\CreditNote;
use App\Models\OrderAddress;
use App\Support\DisplayTime;
use Illuminate\Support\Facades\DB;

/**
 * The printable credit note (05.17 §3): its number and date, the invoice
 * it credits (number and date as issued), the reason, net, VAT and total
 * from the note's own integers, and — as at issue — how much of it was set
 * against that invoice's debt and how much became spendable account
 * balance. A note allocated to an invoice is never counted again as
 * balance. Customer identity is the order's snapshotted address and the
 * company as it was at issue; no costs, staff notes or provider ids.
 */
final class CreditNoteDocumentBuilder
{
    public const REASONS = [
        'return' => 'Returned goods',
        'goodwill' => 'Goodwill',
        'pricing_correction' => 'Price correction',
        'cancellation' => 'Cancelled goods',
        'other' => 'Other',
    ];

    public function build(CreditNote $note): CreditNoteDocument
    {
        $note->loadMissing(['invoice', 'order.addresses', 'order.user', 'company']);
        $invoice = $note->invoice;
        $order = $note->order;
        $company = $note->company ?? ($invoice === null ? null : $invoice->company) ?? ($order === null ? null : $order->company);

        // As at issue: allocations committed with the note itself.
        $allocated = (int) DB::table('credit_note_allocations')->where('credit_note_id', $note->id)->sum('amount_minor');
        $allocated = max(0, min($allocated, $note->total_gross_minor));

        $address = $order?->addresses->firstWhere('address_type', 'billing') ?? $order?->addresses->firstWhere('address_type', 'delivery');
        $user = $order?->user;
        $seller = SellerDetails::fromConfiguration();

        return new CreditNoteDocument([
            'kind' => 'credit_note',
            'title' => 'Credit note',
            'number' => $note->credit_note_number,
            'public_id' => $note->public_id,
            'issued_at' => $note->issued_at->toIso8601ZuluString(),
            'issued_on_display' => DisplayTime::format($note->issued_at, DisplayTime::DATE),
            'reason' => $note->reason,
            'reason_label' => self::REASONS[$note->reason] ?? 'Other',
            'currency' => $note->currency,
            'order_number' => $order?->order_number,
            'customer_reference' => $order?->customer_reference,
            'original_invoice' => $invoice === null ? null : [
                'number' => $invoice->invoice_number,
                'issued_on_display' => DisplayTime::format($invoice->issued_at, DisplayTime::DATE),
            ],
            'seller' => [
                'legal_name' => $seller->legalName,
                'address_lines' => $seller->addressLines,
                'vat_number' => $seller->vatNumber,
                'company_number' => $seller->companyNumber,
            ],
            'customer' => [
                'name' => $company->name ?? ($user === null ? $address?->contact_name : trim($user->first_name.' '.$user->last_name)),
                'account_code' => $company?->account_code,
                'vat_number' => $company?->vat_number,
                'contact_name' => $address?->contact_name,
                'address_lines' => self::lines($address),
            ],
            'totals' => [
                ...self::money('net', $note->subtotal_net_minor),
                ...self::money('vat', $note->tax_minor),
                ...self::money('total_gross', $note->total_gross_minor),
                ...self::money('allocated_to_invoice', $allocated),
                ...self::money('to_account_balance', $company === null ? 0 : $note->total_gross_minor - $allocated),
            ],
            'footer' => array_values(array_filter([
                $seller->legalName === null ? null : $seller->legalName.($seller->companyNumber === null ? '' : ' · Company number '.$seller->companyNumber),
                $seller->vatNumber === null ? null : 'VAT registration '.$seller->vatNumber,
                'This credit note reduces the amount due on the invoice shown. Any remainder is added to your account balance.',
            ])),
        ]);
    }

    /** @return list<string> */
    private static function lines(?OrderAddress $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_values(array_filter([
            $address->company_name, $address->line1, $address->line2, $address->city, $address->county, $address->postcode,
            $address->country_code === 'GB' ? null : $address->country_code,
        ], fn (?string $part) => $part !== null && trim($part) !== ''));
    }

    /** @return array<string, int|string|null> */
    private static function money(string $name, int $minor): array
    {
        return ["{$name}_minor" => $minor, $name => MoneyFormatter::minor($minor)];
    }
}
