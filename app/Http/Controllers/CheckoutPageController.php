<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\TermsKind;
use App\Domain\Ordering\ConsumerCheckout;
use App\Domain\Ordering\PaymentMethod;
use App\Domain\Pricing\DeliveryCountries;
use App\Domain\Storefront\Branding;
use App\Domain\Storefront\PreContractInformation;
use App\Http\Support\ActingCompany;
use App\Http\Support\PriceDisplay;
use App\Models\Address;
use App\Models\Company;
use App\Models\TermsVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The checkout page (06 §9.2–9.3). Signed-in only (05.13 §4.1). The order
 * summary and blockers come from `/checkout/preview`, re-run whenever the
 * delivery country changes; the order is placed through `/checkout`.
 *
 * Saved addresses are offered to prefill the form and are sent back
 * inline — they cannot be referenced by id (`addresses` has no public_id,
 * 02 §4.5), and the order snapshots them anyway (02 §8.4).
 *
 * A public buyer (05.15 §6.1) is offered GB only (rule G), reads and
 * accepts the terms of sale in force (step 4), and sees the pre-contract
 * information before the pay button (§7.1). A trade buyer sees none of
 * these: their terms of trade were accepted with their application.
 */
class CheckoutPageController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $company = ActingCompany::current($request->session(), $user);
        $terms = $company === null ? TermsVersion::current(TermsKind::Sale) : null;

        return Inertia::render('Checkout/Index', [
            'display_mode' => PriceDisplay::checkoutMode($request),
            'is_trade' => $company !== null,
            'company_name' => $company?->name,
            'contact' => [
                'name' => trim("{$user->first_name} {$user->last_name}"),
                'phone' => $user->getAttribute('phone'),
            ],
            'addresses' => $company === null ? [] : $this->savedAddresses($company),
            'countries' => $company === null
                ? array_values(array_filter(DeliveryCountries::available(), fn (array $c) => ConsumerCheckout::servesCountry($c['code'])))
                : DeliveryCountries::available(),
            'terms_of_sale' => $terms === null ? null : [
                'id' => $terms->id,
                'version' => $terms->version,
                'html' => Str::markdown($terms->body_markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            ],
            'pre_contract' => $company === null ? PreContractInformation::build(Branding::current(), $terms)->toArray() : null,
            'payment_methods' => array_map(
                fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()],
                $this->paymentMethods($company),
            ),
            // 07 §6.4: the publishable key only — Stripe Elements in the
            // browser takes the card; the secret never leaves the server.
            'stripe_key' => $this->cardPaymentsAvailable() ? (string) config('services.stripe.key') : null,
        ]);
    }

    /**
     * 05.2 §8.1: on-account first for a company on credit terms; card and
     * BACS for everyone (card only when Stripe is configured).
     *
     * @return list<PaymentMethod>
     */
    private function paymentMethods(?Company $company): array
    {
        $onAccount = $company !== null && $company->payment_terms !== 'prepay';
        $methods = $onAccount
            ? [PaymentMethod::OnAccount, PaymentMethod::Card, PaymentMethod::Bacs]
            : [PaymentMethod::Card, PaymentMethod::Bacs];

        // No card option unless Stripe is configured: never offer a payment
        // method that cannot be taken.
        return $this->cardPaymentsAvailable()
            ? $methods
            : array_values(array_filter($methods, fn (PaymentMethod $m) => $m !== PaymentMethod::Card));
    }

    private function cardPaymentsAvailable(): bool
    {
        return (string) config('services.stripe.key') !== '' && (string) config('services.stripe.secret') !== '';
    }

    /**
     * @return list<array<string, string|bool|null>>
     */
    private function savedAddresses(Company $company): array
    {
        $rows = Address::query()
            ->where('company_id', $company->id)
            ->whereIn('address_type', ['delivery', 'both'])
            ->whereNull('deleted_at')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn (Address $a, int $i) => [
            'key' => "saved-{$i}",
            'label' => $a->getAttribute('label'),
            'contact_name' => $a->getAttribute('contact_name'),
            'phone' => $a->getAttribute('phone'),
            'line1' => (string) $a->getAttribute('line1'),
            'line2' => $a->getAttribute('line2'),
            'city' => (string) $a->getAttribute('city'),
            'county' => $a->getAttribute('county'),
            'postcode' => (string) $a->getAttribute('postcode'),
            'country_code' => trim((string) $a->getAttribute('country_code')),
            'is_default' => (bool) $a->getAttribute('is_default'),
        ])->all());
    }
}
