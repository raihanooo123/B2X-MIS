<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Seo\SeoHead;
use App\Domain\Seo\StructuredData;
use App\Domain\Storefront\Branding;
use App\Domain\Storefront\ProductCards;
use App\Domain\Storefront\ProductDetail;
use App\Domain\Storefront\StorefrontCatalogue;
use App\Domain\Storefront\StorefrontFilters;
use App\Domain\Storefront\StorefrontShell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StorefrontListingRequest;
use App\Http\Support\CartContext;
use App\Http\Support\PriceDisplay;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function category(StorefrontListingRequest $request, string $slug): Response|RedirectResponse
    {
        if ($slug !== strtolower($slug)) {
            return redirect()->to('/c/'.strtolower($slug).($request->getQueryString() === null ? '' : '?'.$request->getQueryString()), 301);
        }

        $category = Category::query()->where('slug', $slug)->where('status', 'active')->first();
        abort_if($category === null, 404);

        $children = DB::table('categories')->where('parent_id', $category->id)->where('status', 'active')
            ->orderBy('position')->orderBy('name')->get(['name', 'slug'])
            ->map(fn ($c) => ['name' => (string) $c->name, 'slug' => (string) $c->slug])->all();

        $breadcrumb = $this->details->breadcrumb((int) $category->id);
        $brand = Branding::current();
        $url = SeoHead::url('/c/'.$category->getAttribute('slug'));
        // The URL's own query string: StorefrontListingRequest merges its
        // normalised defaults (q, sort, after…) into the query bag, so
        // $request->query() is never empty and cannot tell a clean page.
        parse_str($request->getQueryString() ?? '', $query);
        $clean = SeoHead::onlyTrackingParameters($query);
        $trail = [['name' => 'Home', 'url' => SeoHead::url('/')]];
        foreach ($breadcrumb as $crumb) {
            $trail[] = ['name' => $crumb['name'], 'url' => SeoHead::url('/c/'.$crumb['slug'])];
        }
        $title = $category->getAttribute('meta_title');
        $seo = new SeoHead(
            title: (is_string($title) && trim($title) !== '' ? trim($title) : (string) $category->getAttribute('name')).' · '.$brand->name,
            description: SeoHead::describe(
                is_string($category->getAttribute('meta_description')) ? $category->getAttribute('meta_description') : null,
                "Shop {$category->getAttribute('name')} at {$brand->name}.",
            ),
            // 05.11 §4.2: a filtered, sorted or "show more" page is noindex, follow, with no canonical.
            canonical: $clean ? $url : null,
            index: $clean,
            jsonLd: [StructuredData::breadcrumb($trail)],
        );

        return $seo->attach($this->listing($request, $request->filters((int) $category->id), [
            'category' => [
                'name' => (string) $category->getAttribute('name'),
                'slug' => (string) $category->getAttribute('slug'),
                'meta_title' => $category->getAttribute('meta_title'),
                'meta_description' => $category->getAttribute('meta_description'),
                'breadcrumb' => $breadcrumb,
                'children' => array_values($children),
            ],
        ]));
    }

    public function search(StorefrontListingRequest $request): Response
    {
        $filters = $request->filters();
        $brand = Branding::current();

        // 05.11 §4.2: search results are never indexed.
        return (new SeoHead(
            title: ($filters->search === null ? 'Search' : 'Results for “'.$filters->search.'”').' · '.$brand->name,
            index: false,
        ))->attach($this->listing($request, $filters, ['category' => null]));
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
        // 02 §29 (Q-P1): price sorts only for guests and public customers — the
        // viewers who resolve at `base`, where the sort key is exact. Anyone
        // else asking for one gets name order.
        $priceSorts = PriceDisplay::canSwitch($request);
        if (! $priceSorts && in_array($filters->sort, StorefrontCatalogue::PRICE_SORTS, true)) {
            $filters = $filters->withSort('name');
        }
        $page = $this->catalogue->page($filters, $request->cursor(), StorefrontCatalogue::PAGE_SIZE, PriceDisplay::mode($request));

        return Inertia::render('Storefront/Listing', [
            ...$extra,
            'shell' => fn () => $this->shell->props($owner),
            'filters' => $filters->toArray(),
            'products' => $this->cards->cards($page['product_ids'], $owner?->companyId),
            'next_cursor' => $page['next_cursor'],
            // A later page appends to the grid; the first page replaces it.
            'is_continuation' => $request->cursor() !== null,
            'brands' => fn () => $this->catalogue->brands($filters),
            'price_sorts' => $priceSorts,
        ]);
    }
}
