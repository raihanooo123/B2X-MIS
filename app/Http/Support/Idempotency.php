<?php

namespace App\Http\Support;

use App\Http\Exceptions\ApiException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 06 §6 idempotency for state-changing POSTs that require it (`/checkout`):
 *
 *   - the `Idempotency-Key` header is required (UUID or ULID);
 *   - the first request runs and its response is stored for 24 hours;
 *   - a repeat with the same key and body returns the stored response
 *     without running again;
 *   - the same key with a different body is 409 `idempotency_key_reuse`;
 *   - while the first is still running, a repeat is 409
 *     `idempotency_in_progress` — a double-clicked "Place order" cannot
 *     race itself into two orders.
 *
 * Keys are scoped to the caller (user, else session), so one buyer's key
 * can never replay another's response. Stored in the cache (Redis). 06
 * §16's stronger guarantee — one side effect even after the stored
 * response expires, "via the underlying unique constraint" — needs a
 * column on `orders` that 02 does not have yet.
 */
final class Idempotency
{
    public const TTL_SECONDS = 86400;

    private const LOCK_SECONDS = 60;

    /**
     * @param  Closure(): JsonResponse  $handler
     */
    public static function run(Request $request, string $scope, Closure $handler): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (preg_match('/^[0-9A-Za-z-]{16,64}$/', $key) !== 1) {
            return ApiException::envelope($request, 422, 'idempotency_key_required', 'Send an Idempotency-Key header (a UUID or ULID) with this request.');
        }

        $caller = $request->user()?->getAuthIdentifier() ?? ($request->hasSession() ? $request->session()->getId() : 'anonymous');
        $cacheKey = 'idempotency:'.$scope.':'.hash('sha256', $caller.'|'.$key);
        $bodyHash = hash('sha256', (string) json_encode(self::canonical($request->all())));

        $stored = Cache::get($cacheKey);
        if (is_array($stored)) {
            return self::replay($request, $stored, $bodyHash);
        }

        $lock = Cache::lock($cacheKey.':lock', self::LOCK_SECONDS);
        if (! $lock->get()) {
            return ApiException::envelope($request, 409, 'idempotency_in_progress', 'This request is already being processed.');
        }

        try {
            // Re-check under the lock: a request that finished between the
            // read above and taking the lock has stored its response.
            $stored = Cache::get($cacheKey);
            if (is_array($stored)) {
                return self::replay($request, $stored, $bodyHash);
            }

            try {
                $response = $handler();
            } catch (ApiException $e) {
                $response = $e->render($request);
            }

            // 5xx is not a result — the client may retry with the same key.
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'body_hash' => $bodyHash,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getData(true),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function replay(Request $request, array $stored, string $bodyHash): JsonResponse
    {
        if (($stored['body_hash'] ?? null) !== $bodyHash) {
            return ApiException::envelope($request, 409, 'idempotency_key_reuse', 'This Idempotency-Key was already used with a different request.');
        }

        return response()->json($stored['body'] ?? null, (int) ($stored['status'] ?? 200))
            ->header('Idempotent-Replayed', 'true');
    }

    /**
     * Key order must not change the hash.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
