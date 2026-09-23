<?php

namespace App\Http\Exceptions;

use App\Domain\Ordering\Exceptions\CartItemRejectedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Doc 06 §4's single error envelope. Thrown by API controllers for
 * business-rule refusals (422 with a stable `code`), and used by
 * bootstrap/app.php to render validation, not-found and authorisation
 * failures on `api/*` in the same shape.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  list<array{field: ?string, code: string, message: string, meta?: array<string, mixed>}>  $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * 05.1 §7 / 06 §4: a buyer who pasted 60 lines must be told that
     * line 37 failed and why. One `details[]` entry per rejected line —
     * `field` (`lines.37.sku_id`), a stable `code`, a message, and
     * `meta.line_index` (0-based, the same N as in `field`) — and a
     * top-level message naming the failed lines in 1-based terms.
     *
     * @param  list<CartItemRejectedException>  $rejections
     * @param  int|null  $totalLines  lines in the request, for a bulk add
     */
    public static function fromCartItemRejections(array $rejections, ?int $totalLines = null): self
    {
        $details = array_map(
            fn (CartItemRejectedException $e) => [
                'field' => $e->field,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'meta' => $e->lineIndex === null ? $e->meta : ['line_index' => $e->lineIndex] + $e->meta,
            ],
            $rejections,
        );

        $lineNumbers = array_values(array_unique(array_filter(
            array_map(fn (CartItemRejectedException $e) => $e->lineIndex === null ? null : $e->lineIndex + 1, $rejections),
            fn (?int $n) => $n !== null,
        )));

        if ($lineNumbers === []) {
            $message = count($rejections) === 1
                ? "This item could not be added to the cart: {$rejections[0]->getMessage()}"
                : 'These items could not be added to the cart.';
        } else {
            $of = $totalLines === null ? '' : " of {$totalLines}";
            $message = count($lineNumbers)."{$of} line(s) could not be added, so nothing was added: line(s) ".implode(', ', $lineNumbers).'. See details for each reason.';
        }

        return new self(422, 'invalid_cart_item', $message, $details);
    }

    public function render(Request $request): JsonResponse
    {
        return self::envelope($request, $this->status, $this->errorCode, $this->getMessage(), $this->details);
    }

    /**
     * @param  list<array{field: ?string, code: string, message: string, meta?: array<string, mixed>}>  $details
     */
    public static function envelope(Request $request, int $status, string $code, string $message, array $details = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $request->header('X-Request-Id') ?? (string) Str::ulid(),
            ],
        ], $status);
    }
}
