<?php

namespace App\Domain\Storefront;

use App\Domain\Cms\CurrentPages;
use App\Domain\Ordering\CartOwner;
use App\Domain\Ordering\CartService;
use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 05.15 §3.2 — what the storefront header needs beyond the shared props:
 * the category menu (top two levels, tree order), the cart's line count and
 * the footer's legal and help links (05.11 §2.1: only pages with a version
 * in force, and the terms of sale when a `sale` version is in force).
 * A few queries; the brand and the price switch are shared on every page
 * (HandleInertiaRequests).
 */
final class StorefrontShell
{
    public function __construct(
        private readonly CartService $carts = new CartService,
    ) {}

    /** @return array{categories: list<array<string, mixed>>, cart_count: int, legal_pages: list<array{key: string, label: string, path: string}>} */
    public function props(?CartOwner $owner): array
    {
        return [
            'categories' => $this->categories(),
            'cart_count' => $this->cartCount($owner),
            'legal_pages' => self::legalLinks(),
        ];
    }

    /** @return list<array{key: string, label: string, path: string}> */
    public static function legalLinks(): array
    {
        // Both from CurrentPages' cache: no query per page view (05.11 §2.1).
        $links = CurrentPages::links();
        if (CurrentPages::saleTermsInForce()) {
            array_unshift($links, ['key' => 'terms', 'label' => 'Terms of sale', 'path' => '/terms']);
        }

        return $links;
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

    /**
     * Home page department tiles (05.15 §5.1): each top-level category with
     * its active product count across the subtree and one product image,
     * featured first. Three queries, however many departments.
     *
     * @return list<array{slug: string, name: string, product_count: int, image_url: string|null}>
     */
    public function departments(): array
    {
        $roots = DB::table('categories')->where('status', 'active')->where('depth', 0)
            ->orderBy('path')->orderBy('position')->orderBy('name')->get(['id', 'slug', 'name']);
        if ($roots->isEmpty()) {
            return [];
        }
        $rootIds = array_values($roots->map(fn ($r) => (int) $r->id)->all());

        $counts = DB::table('products as p')
            ->join('category_closure as cc', 'cc.descendant_id', '=', 'p.primary_category_id')
            ->whereIn('cc.ancestor_id', $rootIds)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->groupBy('cc.ancestor_id')
            ->selectRaw('cc.ancestor_id, COUNT(DISTINCT p.id) AS n')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->ancestor_id => (int) $row->n]);

        // One product per department, featured first, then newest.
        $pick = DB::table('products as p')
            ->join('category_closure as cc', 'cc.descendant_id', '=', 'p.primary_category_id')
            ->whereIn('cc.ancestor_id', $rootIds)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('media as m')->whereColumn('m.product_id', 'p.id')->where('m.media_type', 'image'))
            ->selectRaw('DISTINCT ON (cc.ancestor_id) cc.ancestor_id, p.id')
            ->orderBy('cc.ancestor_id')
            ->orderByDesc('p.is_featured')
            ->orderByRaw('p.published_at DESC NULLS LAST')
            ->orderByDesc('p.id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->ancestor_id => (int) $row->id]);

        $images = [];
        Media::query()->whereIn('product_id', $pick->values()->all())->where('media_type', 'image')
            ->orderBy('position')->orderBy('id')->get(['product_id', 'disk', 'path'])
            ->each(function (Media $m) use (&$images) {
                $productId = (int) $m->getAttribute('product_id');
                if (isset($images[$productId])) {
                    return;
                }
                try {
                    $images[$productId] = Storage::disk((string) $m->getAttribute('disk'))->url((string) $m->getAttribute('path'));
                } catch (Throwable) {
                    // No URL, no image: the tile shows without one.
                }
            });

        return array_values($roots->map(fn ($r) => [
            'slug' => (string) $r->slug,
            'name' => (string) $r->name,
            'product_count' => $counts->get((int) $r->id, 0),
            'image_url' => $images[$pick->get((int) $r->id, 0)] ?? null,
        ])->all());
    }

    private function cartCount(?CartOwner $owner): int
    {
        if ($owner === null) {
            return 0;
        }

        return $this->carts->findCartFor($owner)?->lines()->count() ?? 0;
    }
}
