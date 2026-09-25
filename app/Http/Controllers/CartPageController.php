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
 * one, confirmed at checkout. Carriage (05.6 §8) is estimated the same
 * way, from that address's postcode; without one it is shown at checkout.
 */
class CartPageController extends Controller
{
    public function show(Request $request): Response
    {
        $address = $this->defaultDeliveryAddress($request);
        $country = $address === null ? 'GB' : trim((string) $address->getAttribute('country_code'));

        return Inertia::render('Cart/Index', [
            'display_mode' => PriceDisplay::mode($request),
            'estimate_country' => ['code' => $country, 'name' => DeliveryCountries::name($country)],
            'estimate_postcode' => $address?->getAttribute('postcode'),
        ]);
    }

    private function defaultDeliveryAddress(Request $request): ?Address
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        $company = ActingCompany::current($request->session(), $user);
        if ($company === null) {
            return null;
        }

        return Address::query()
            ->where('company_id', $company->id)
            ->whereIn('address_type', ['delivery', 'both'])
            ->where('is_default', true)
            ->orderByRaw("address_type = 'delivery' DESC")
            ->first();
    }
}
