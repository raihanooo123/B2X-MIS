<?php

namespace App\Domain\Storefront;

use App\Domain\Ordering\CartOwner;
use App\Domain\Ordering\CartService;
use Illuminate\Support\Facades\DB;

/**
 * 05.15 §3.2 — what the storefront header needs beyond the shared props:
 * the category menu (top two levels, tree order) and the cart's line count.
 * Two queries; the brand and the price switch are shared on every page
 * (HandleInertiaRequests).
 */
final class StorefrontShell
{
    public function __construct(
        private readonly CartService $carts = new CartService,
    ) {}

    /** @return array{categories: list<array<string, mixed>>, cart_count: int} */
    public function props(?CartOwner $owner): array
    {
        return [
            'categories' => $this->categories(),
            'cart_count' => $this->cartCount($owner),
        ];
    }

    /** @return list<array{slug: string, name: string, children: list<array{slug: string, name: string}>}> */
    public function categories(): array
    {
        $rows = DB::table('categories')
            ->where('status', 'active')
            ->where('depth', '<=', 1)
            ->orderBy('path')
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'slug', 'name', 'depth']);

        $roots = [];
        foreach ($rows as $row) {
            if ((int) $row->depth === 0) {
                $roots[(int) $row->id] = ['slug' => (string) $row->slug, 'name' => (string) $row->name, 'children' => []];
            }
        }
        foreach ($rows as $row) {
            if ((int) $row->depth === 1 && isset($roots[(int) $row->parent_id])) {
                $roots[(int) $row->parent_id]['children'][] = ['slug' => (string) $row->slug, 'name' => (string) $row->name];
            }
        }

        return array_values($roots);
    }

    private function cartCount(?CartOwner $owner): int
    {
        if ($owner === null) {
            return 0;
        }

        return $this->carts->findCartFor($owner)?->lines()->count() ?? 0;
    }
}
