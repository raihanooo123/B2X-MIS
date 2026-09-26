<?php

namespace App\Http\Middleware;

use App\Domain\Identity\CompanyMemberships;
use App\Http\Support\ActingCompany;
use App\Models\GoodsReceipt;
use App\Models\Shipment;
use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn () => $this->auth($request),
            'flash' => fn () => ['status' => $request->hasSession() ? $request->session()->get('status') : null],
        ];
    }

    /**
     * Who is signed in and which company they act for (05.13 §6.3) — for
     * the account menu. Nothing sensitive: no cost, no credit figures.
     *
     * @return array<string, mixed>|null
     */
    private function auth(Request $request): ?array
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->hasSession()) {
            return null;
        }

        $company = ActingCompany::current($request->session(), $user);

        return [
            'user' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'two_factor_enabled' => $user->two_factor_enabled,
            ],
            'company' => $company === null ? null : ['id' => $company->public_id, 'name' => $company->name],
            'can_switch_company' => count(CompanyMemberships::ids($user)) > 1,
            'staff_navigation' => [
                'admin' => Gate::allows('accessAdminPanel', User::class),
                'goods_in' => Gate::allows('viewAny', GoodsReceipt::class),
                'picking' => Gate::allows('viewAny', Shipment::class),
                'dispatch' => Gate::allows('viewAny', Shipment::class),
                'stocktake' => Gate::allows('viewAny', Stocktake::class),
            ],
        ];
    }
}
