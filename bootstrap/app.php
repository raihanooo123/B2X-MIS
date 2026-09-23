<?php

use App\Http\Exceptions\ApiException;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
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
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // 06 §1: the first-party SPA authenticates to /api with the
        // session cookie + CSRF. These are the same four middleware
        // Sanctum's stateful mode adds for first-party requests; without
        // them `$request->user()` is always null on /api and guest carts
        // have no session to key on. Swap for `statefulApi()` if
        // laravel/sanctum is installed for Phase 4 token clients.
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidateCsrfToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
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
