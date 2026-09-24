<?php

namespace App\Http\Middleware;

use App\Http\Exceptions\ApiException;
use App\Http\Support\ActingCompany;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 05.13 §6.3: a user in several companies must choose one before
 * reaching anything that prices or carts. Pages redirect to the chooser,
 * remembering where the user was going; /api answers 409
 * `company_choice_required`.
 */
final class EnsureCompanyChosen
{
    public const ALLOWED = ['company.choose*', 'logout', 'two-factor.*', 'verification.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $request->hasSession() || $request->routeIs(...self::ALLOWED)
            || ! ActingCompany::needsChoice($request->session(), $user)) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            return ApiException::envelope($request, 409, 'company_choice_required', 'Choose which company you are ordering for.');
        }

        return redirect()->guest(route('company.choose'));
    }
}
