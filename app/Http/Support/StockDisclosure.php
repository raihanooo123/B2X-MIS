<?php

namespace App\Http\Support;

use App\Domain\Inventory\StockLabels;
use App\Domain\Ordering\CheckoutBlocker;
use Illuminate\Http\Request;

/**
 * 05.15 §5.3, §12 Q2 — shortage messages for a public viewer. When a
 * basket asks for more than is available, a trade user or staff member is
 * told the figure (StockVisibility). Everyone else is told it only when it
 * is small (*Only 7 left*); a larger shortfall is reported without a
 * number, so a huge quantity cannot be used to read the stock position.
 */
final class StockDisclosure
{
    public const NO_FIGURE_MESSAGE = 'We can’t supply that quantity right now. Please try a smaller quantity.';

    /** @return array{field: ?string, code: string, message: string, meta: array<string, mixed>} */
    public static function blocker(CheckoutBlocker $blocker, Request $request): array
    {
        $array = $blocker->toArray();
        if ($blocker->code !== 'insufficient_stock' || StockVisibility::exactFigures($request)) {
            return $array;
        }

        $available = $array['meta']['available_base_qty'] ?? null;
        $left = is_int($available) ? StockLabels::disclosable($available) : null;

        $array['message'] = $left === null ? self::NO_FIGURE_MESSAGE : "Only {$left} left.";
        $array['meta']['available_base_qty'] = $left;

        return $array;
    }

    /** The place-order shortfall line (CheckoutController), with the same rule. */
    public static function shortfall(int $available, string $item, Request $request): string
    {
        if (StockVisibility::exactFigures($request)) {
            return "Only {$available} units of {$item} are available.";
        }

        $left = StockLabels::disclosable($available);

        return $left === null ? "We can’t supply that quantity of {$item} right now." : "Only {$left} of {$item} left.";
    }

    public static function available(int $available, Request $request): ?int
    {
        return StockVisibility::exactFigures($request) ? $available : StockLabels::disclosable($available);
    }
}
