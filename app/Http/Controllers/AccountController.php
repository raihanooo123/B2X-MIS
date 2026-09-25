<?php

namespace App\Http\Controllers;

use App\Domain\Identity\RecoveryCodes;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Support\ActingCompany;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's account page: who they are, which company they are
 * ordering for, and security — two-factor authentication and its recovery
 * codes (05.13 §12.2). Regenerating codes and turning 2FA off are
 * TwoFactorSetupController actions that return here.
 */
class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $company = ActingCompany::current($request->session(), $user);
        $pending = $request->session()->get(TwoFactorSetupController::REGENERATED_CODES);

        return Inertia::render('Account/Index', [
            'user' => [
                'name' => trim("{$user->first_name} {$user->last_name}"),
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
            ],
            'company' => $company === null ? null : ['name' => $company->name, 'account_code' => $company->account_code],
            'two_factor' => [
                'enabled' => $user->two_factor_enabled,
                'required' => $user->isStaff(),
                'remaining_codes' => $user->two_factor_enabled ? RecoveryCodes::remaining($user) : 0,
                'low_watermark' => RecoveryCodes::LOW_WATERMARK,
                // A regenerated set awaiting "I have saved these" — the old set still works.
                'pending_codes' => $user->two_factor_enabled && is_array($pending) ? array_values($pending) : null,
            ],
            'status' => $request->session()->get('status'),
        ]);
    }
}
