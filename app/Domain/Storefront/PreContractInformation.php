<?php

namespace App\Domain\Storefront;

use App\Filament\Support\MoneyFormatter;
use App\Models\TermsVersion;

/**
 * 05.15 §7.1 — the pre-contract information a consumer is given before
 * paying (CCR 2013 Sch. 2), shown on checkout above the pay button and
 * repeated in full in the body of the order confirmation email (reg. 16's
 * durable medium; a link would not do).
 *
 * Generated from `seller.*` and `brand.*` (Branding) and the terms of sale
 * in force, never hand-written per order. The return-cost statement
 * (§7.2, reg. 35(5)) is part of it: public checkout go-live is blocked
 * until it is on the checkout and in the email.
 *
 * Plain strings only, so the React page and both mail templates render
 * the same text without any markup of their own. Needs legal review
 * before public checkout goes live (05.15 §12 Q1).
 */
final readonly class PreContractInformation
{
    /**
     * @param  list<array{heading: string, paragraphs: list<string>}>  $sections
     */
    public function __construct(
        public array $sections,
        public ?int $termsVersionId,
        public ?string $termsVersion,
    ) {}

    /**
     * @param  string|null  $total  the order's formatted total, inc VAT and
     *                              delivery, once known (the email); on
     *                              checkout the page shows it by the button
     * @param  bool  $pallet  the consignment is a pallet, which cannot be posted back (02 §27)
     * @param  int|null  $returnCostGrossMinor  the estimated return cost of that pallet; null: we collect at our cost
     */
    public static function build(Branding $brand, ?TermsVersion $terms, ?string $total = null, bool $pallet = false, ?int $returnCostGrossMinor = null): self
    {
        $seller = $brand->seller;
        $tradingName = $seller->legalName ?? $brand->name;

        $identity = [$seller->legalName !== null && $seller->legalName !== $brand->name
            ? "{$seller->legalName}, trading as {$brand->name}."
            : "{$tradingName}."];
        if ($seller->addressLines !== []) {
            $identity[] = 'Address: '.implode(', ', $seller->addressLines).'.';
        }
        if ($seller->companyNumber !== null) {
            $identity[] = "Company number {$seller->companyNumber}.";
        }
        if ($seller->vatNumber !== null) {
            $identity[] = "VAT number {$seller->vatNumber}.";
        }
        $contact = self::contact($brand);
        if ($contact !== null) {
            $identity[] = "Contact us {$contact}.";
        }

        $sections = [
            ['heading' => 'Who you are buying from', 'paragraphs' => $identity],
            ['heading' => 'Price', 'paragraphs' => [
                $total === null
                    ? 'The total price shown with your order includes VAT and delivery. There are no other charges.'
                    : "The total price of your order is {$total}, including VAT and delivery. There are no other charges.",
            ]],
            ['heading' => 'Payment and delivery', 'paragraphs' => [
                'Payment by card is taken when you place your order. If you pay by bank transfer, we dispatch once your payment has cleared.',
                'We deliver to addresses in Great Britain only, without undue delay and within 30 days of your order unless we agree another date with you.',
            ]],
            ['heading' => 'Your right to cancel', 'paragraphs' => [
                'You may cancel your order for any reason within 14 days after the day you receive the goods. If your order arrives in several parts, the 14 days run from the day you receive the last part.',
                'To cancel, tell us clearly'.($contact === null ? '' : " {$contact}").'. You may use the model cancellation form in your order confirmation email, but you do not have to.',
                'We refund you within 14 days after we receive the goods back, or after you show us that you have sent them, whichever is earlier. We refund the delivery you paid up to the cost of our least expensive standard delivery. We refund to the card or account you paid with.',
            ]],
            ['heading' => 'Returning goods', 'paragraphs' => [
                $pallet ? self::palletReturnStatement($returnCostGrossMinor) : 'If you cancel because you changed your mind, you pay the direct cost of returning the goods to us.',
                'If goods are faulty, damaged or not what you ordered, we pay the cost of returning them, including collecting goods that cannot reasonably be sent by post.',
            ]],
            ['heading' => 'Faulty goods', 'paragraphs' => [
                'Your legal rights under the Consumer Rights Act 2015 are not affected. You may reject faulty goods for a full refund within 30 days, and after that ask for a repair or replacement.',
            ]],
            ['heading' => 'Complaints', 'paragraphs' => [
                $contact === null
                    ? "If you are unhappy with your order, please contact {$tradingName} and we will do our best to put it right."
                    : "If you are unhappy with your order, please contact us {$contact} and we will do our best to put it right.",
            ]],
        ];

        if ($terms !== null) {
            $sections[] = ['heading' => 'Terms of sale', 'paragraphs' => [
                "Your order is made on our terms of sale, version {$terms->version}, which you accept when you place it.",
            ]];
        }

        return new self($sections, $terms?->id, $terms?->version);
    }

    /**
     * 02 §27, CCR Sch. 2 para (l): goods that cannot be posted back, with the
     * estimated cost of returning them, or our undertaking to collect them.
     * One wording for the checkout page, the confirmation email and
     * `rma.approved`.
     */
    public static function palletReturnStatement(?int $returnCostGrossMinor): string
    {
        return $returnCostGrossMinor === null
            ? 'These goods are delivered on a pallet and cannot be returned by post. If you cancel, we collect them at our cost.'
            : 'These goods are delivered on a pallet and cannot be returned by post. If you cancel because you changed your mind, you pay the direct cost of returning them, which we estimate at '.MoneyFormatter::minor($returnCostGrossMinor).' including VAT (our own pallet rate for this delivery).';
    }

    /**
     * CCR Sch. 3 Part B — the model cancellation form, for the confirmation
     * email. The trader's details are filled in; the rest is the buyer's.
     *
     * @return array{heading: string, paragraphs: list<string>}
     */
    public static function modelCancellationForm(Branding $brand): array
    {
        $seller = $brand->seller;
        $to = array_values(array_filter([
            $seller->legalName ?? $brand->name,
            $seller->addressLines === [] ? null : implode(', ', $seller->addressLines),
            $brand->supportEmail,
        ]));

        return ['heading' => 'Model cancellation form', 'paragraphs' => [
            '(Complete and return this form only if you wish to cancel the contract.)',
            'To: '.implode(', ', $to),
            'I/We* hereby give notice that I/We* cancel my/our* contract of sale of the following goods*:',
            'Ordered on* / received on*:',
            'Name of consumer(s):',
            'Address of consumer(s):',
            'Signature of consumer(s) (only if this form is notified on paper):',
            'Date:',
            '(* Delete as appropriate.)',
        ]];
    }

    /** "by email at …, or by phone on …", or null when neither is set. */
    private static function contact(Branding $brand): ?string
    {
        $ways = array_values(array_filter([
            $brand->supportEmail === null ? null : "by email at {$brand->supportEmail}",
            $brand->supportPhone === null ? null : "by phone on {$brand->supportPhone}",
        ]));

        return $ways === [] ? null : implode(', or ', $ways);
    }

    /**
     * For the checkout page (Inertia props).
     *
     * @return array{sections: list<array{heading: string, paragraphs: list<string>}>, terms_version_id: int|null, terms_version: string|null}
     */
    public function toArray(): array
    {
        return [
            'sections' => $this->sections,
            'terms_version_id' => $this->termsVersionId,
            'terms_version' => $this->termsVersion,
        ];
    }
}
