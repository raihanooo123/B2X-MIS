<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Storefront\ProductCards;
use App\Domain\Storefront\ProductDetail;
use App\Domain\Storefront\StorefrontCatalogue;
use App\Domain\Storefront\StorefrontFilters;
use App\Domain\Storefront\StorefrontShell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StorefrontListingRequest;
use App\Http\Support\CartContext;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.15 §5.1–5.2 — the category page (`/c/{slug}`, the whole subtree) and
 * search results (`/search?q=`). Both render one product grid, a page of
 * StorefrontCatalogue at a time with "Show more" (keyset, never OFFSET),
 * priced for whoever is looking.
 */
class CatalogueController extends Controller
{
    public function __construct(
        private readonly StorefrontCatalogue $catalogue = new StorefrontCatalogue,
        private readonly ProductCards $cards = new ProductCards,
        private readonly ProductDetail $details = new ProductDetail,
        private readonly StorefrontShell $shell = new StorefrontShell,
        private readonly CartContext $cartContext = new CartContext,
    ) {}

    public function category(StorefrontListingRequest $request, string $slug): Response
    {
        $category = Category::query()->where('slug', $slug)->where('status', 'active')->first();
        abort_if($category === null, 404);

        $children = DB::table('categories')->where('parent_id', $category->id)->where('status', 'active')
            ->orderBy('position')->orderBy('name')->get(['name', 'slug'])
            ->map(fn ($c) => ['name' => (string) $c->name, 'slug' => (string) $c->slug])->all();

        return $this->listing($request, $request->filters((int) $category->id), [
            'category' => [
                'name' => (string) $category->getAttribute('name'),
                'slug' => (string) $category->getAttribute('slug'),
                'meta_title' => $category->getAttribute('meta_title'),
                'meta_description' => $category->getAttribute('meta_description'),
                'breadcrumb' => $this->details->breadcrumb((int) $category->id),
                'children' => array_values($children),
            ],
        ]);
    }

    public function search(StorefrontListingRequest $request): Response
    {
        return $this->listing($request, $request->filters(), ['category' => null]);
    }

    /**
     * 05.15 §5.3a: up to 6 matches while the buyer types, from the same
     * search as the grid. JSON, not a page.
     */
    public function suggest(StorefrontListingRequest $request): JsonResponse
    {
        $filters = $request->filters();
        if ($filters->search === null || mb_strlen($filters->search) < 2) {
            return response()->json(['data' => []]);
        }

        $owner = $this->cartContext->owner($request, createGuestToken: false);
        $page = $this->catalogue->page($filters, null, 6);
        $cards = $this->cards->cards($page['product_ids'], $owner?->companyId);

        return response()->json(['data' => array_map(fn (array $c) => [
            'name' => $c['name'],
            'slug' => $c['slug'],
            'thumbnail_url' => $c['thumbnail_url'],
            'price' => $c['price'],
        ], $cards)]);
    }

    /** @param  array<string, mixed>  $extra */
    private function listing(StorefrontListingRequest $request, StorefrontFilters $filters, array $extra): Response
    {
        $owner = $this->cartContext->owner($request, createGuestToken: false);
        $page = $this->catalogue->page($filters, $request->cursor());

        return Inertia::render('Storefront/Listing', [
            ...$extra,
            'shell' => fn () => $this->shell->props($owner),
            'filters' => $filters->toArray(),
            'products' => $this->cards->cards($page['product_ids'], $owner?->companyId),
            'next_cursor' => $page['next_cursor'],
            // A later page appends to the grid; the first page replaces it.
            'is_continuation' => $request->cursor() !== null,
            'brands' => fn () => $this->catalogue->brands($filters),
        ]);
    }
}
