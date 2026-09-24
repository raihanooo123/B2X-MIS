<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\DeliveryCountries;
use App\Http\Support\ActingCompany;
use App\Http\Support\PriceDisplay;
use App\Models\Address;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cart page (06 §8 `/cart`). Lines and totals are fetched client-side
 * from `/cart` and `/checkout/preview`, so nothing here duplicates them.
 *
 * VAT depends on the delivery country (03 §10), which is chosen at
 * checkout. Until then the cart prices for the account's default delivery
 * country, or the United Kingdom, and says so — an estimate labelled as
 * one, confirmed at checkout.
 */
class CartPageController extends Controller
{
    public function show(Request $request): Response
    {
        $country = $this->defaultCountry($request) ?? 'GB';

        return Inertia::render('Cart/Index', [
            'display_mode' => PriceDisplay::mode($request),
            'estimate_country' => ['code' => $country, 'name' => DeliveryCountries::name($country)],
        ]);
    }

    private function defaultCountry(Request $request): ?string
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        $company = ActingCompany::current($request->session(), $user);
        if ($company === null) {
            return null;
        }

        $code = Address::query()
            ->where('company_id', $company->id)
            ->whereIn('address_type', ['delivery', 'both'])
            ->where('is_default', true)
            ->orderByRaw("address_type = 'delivery' DESC")
            ->value('country_code');

        return $code === null ? null : trim((string) $code);
    }
}
