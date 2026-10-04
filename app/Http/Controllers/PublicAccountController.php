<?php

namespace App\Http\Controllers;

use App\Domain\Storefront\DeliveryAddressBook;
use App\Domain\Storefront\PublicAccountHistory;
use App\Domain\Storefront\StorefrontShell;
use App\Http\Requests\Web\AccountHistoryRequest;
use App\Http\Requests\Web\SaveDeliveryAddressRequest;
use App\Http\Support\CartContext;
use App\Models\Address;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** 05.15 §6.4: customer account pages; domain services hold queries and mutations. */
class PublicAccountController extends Controller
{
    public function __construct(
        private readonly PublicAccountHistory $history = new PublicAccountHistory,
        private readonly DeliveryAddressBook $addresses = new DeliveryAddressBook,
    ) {}

    private function customer(Request $request): User
    {
        Gate::authorize('publicShopping', User::class);
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function shell(Request $request): array
    {
        return (new StorefrontShell)->props((new CartContext)->owner($request, createGuestToken: false));
    }

    public function orders(AccountHistoryRequest $request): Response
    {
        $user = $this->customer($request);

        return Inertia::render('Account/Orders', ['history' => $this->history->orders($user, $request->cursor('orders')), 'shell' => $this->shell($request)]);
    }

    public function receipts(AccountHistoryRequest $request): Response
    {
        $user = $this->customer($request);

        return Inertia::render('Account/Receipts', ['history' => $this->history->receipts($user, $request->cursor('receipts')), 'shell' => $this->shell($request)]);
    }

    public function download(Request $request, string $receipt): StreamedResponse
    {
        $user = $this->customer($request);
        $invoice = Invoice::query()->whereNull('company_id')->where('public_id', $receipt)
            ->where('status', '<>', 'void')
            ->whereHas('order', fn ($q) => $q->whereNull('company_id')->where('user_id', $user->id))->firstOrFail();
        Gate::authorize('downloadReceipt', $invoice);
        $pdf = $invoice->archivedPdf()->where('is_customer_visible', true)->first();
        abort_if($pdf === null || ! Storage::disk($pdf->disk)->exists($pdf->path), 404, 'Receipt PDF not ready.');

        return Storage::disk($pdf->disk)->download($pdf->path, $invoice->invoice_number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function addresses(Request $request): Response
    {
        $user = $this->customer($request);
        Gate::authorize('viewAny', Address::class);

        return Inertia::render('Account/Addresses', ['addresses' => $this->addresses->listing($user), 'shell' => $this->shell($request), 'status' => $request->session()->get('status')]);
    }

    public function storeAddress(SaveDeliveryAddressRequest $request): RedirectResponse
    {
        $user = $this->customer($request);
        $this->addresses->save($user, $request->validated());

        return redirect()->route('account.addresses')->with('status', 'Delivery address saved.');
    }

    public function updateAddress(SaveDeliveryAddressRequest $request, string $address): RedirectResponse
    {
        $user = $this->customer($request);
        $this->addresses->save($user, $request->validated(), $address);

        return redirect()->route('account.addresses')->with('status', 'Delivery address updated.');
    }

    public function deleteAddress(Request $request, string $address): RedirectResponse
    {
        $this->addresses->delete($this->customer($request), $address);

        return redirect()->route('account.addresses')->with('status', 'Delivery address deleted.');
    }

    public function defaultAddress(Request $request, string $address): RedirectResponse
    {
        $this->addresses->makeDefault($this->customer($request), $address);

        return redirect()->route('account.addresses')->with('status', 'Default delivery address updated.');
    }
}
