<?php

use App\Domain\Identity\Exceptions\CompanyChoiceRequiredException;
use App\Http\Exceptions\ApiException;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsureCompanyChosen;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireStaffTwoFactor;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 05.13 §6.1, §12–13: session limits and account status, mandatory
        // staff 2FA, the company choice — in that order, on every page and
        // /api call. AuthenticateSession ends other sessions when the
        // password changes (07 §6.1), whatever the session driver.
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AuthenticateSession::class,
            EnforceSessionPolicy::class,
            RequireStaffTwoFactor::class,
            EnsureCompanyChosen::class,
        ]);

        // 06 §1: the first-party app authenticates to /api with the session
        // cookie + CSRF, through Laravel Sanctum's stateful middleware
        // (cookies, session, CSRF, AuthenticateSession) for requests from
        // the app's own origin (SANCTUM_STATEFUL_DOMAINS). No API tokens
        // for first-party clients.
        $middleware->statefulApi();
        $middleware->api(append: [
            EnforceSessionPolicy::class,
            RequireStaffTwoFactor::class,
            EnsureCompanyChosen::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('order-pad'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 05.13 §6.3: a multi-company user who has not chosen.
        $exceptions->render(function (CompanyChoiceRequiredException $e, Request $request) {
            return $request->is('api/*')
                ? ApiException::envelope($request, 409, 'company_choice_required', $e->getMessage())
                : redirect()->guest(route('company.choose'));
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('api/*')
                ? ApiException::envelope($request, 401, 'unauthenticated', 'Sign in to continue.')
                : null;
        });

        // 06 §4: one error envelope for every /api failure. ApiException
        // renders itself; the framework's own failures are mapped here.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $details = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $details[] = ['field' => $field, 'code' => 'invalid', 'message' => $message];
                }
            }

            return ApiException::envelope($request, 422, 'validation_failed', 'The request is invalid.', $details);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return $request->is('api/*')
                ? ApiException::envelope($request, 404, 'not_found', 'Not found.')
                : null;
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            return $request->is('api/*')
                ? ApiException::envelope($request, 403, 'forbidden', 'This action is not permitted.')
                : null;
        });
    })->create();
