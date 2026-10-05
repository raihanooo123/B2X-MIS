<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Seo\SeoHead;
use App\Domain\Seo\StructuredData;
use App\Domain\Storefront\Branding;
use App\Domain\Storefront\ProductDetail;
use App\Domain\Storefront\StorefrontShell;
use App\Http\Controllers\Controller;
use App\Http\Support\CartContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.15 §5.3 — the product page, `/p/{slug}`. Adding to the basket goes
 * through the existing cart API (06 §8), which guests may use too.
 *
 * Head tags and JSON-LD (05.11 §4–5): the structured data is always the
 * logged-out view, so a trade viewer's page is priced for them but its
 * `Product` block carries the `base` price. A slug in another case is a
 * 301 to the lower-case canonical URL.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductDetail $details = new ProductDetail,
        private readonly StorefrontShell $shell = new StorefrontShell,
        private readonly CartContext $cartContext = new CartContext,
    ) {}

    public function show(Request $request, string $slug): Response|RedirectResponse
    {
        if ($slug !== strtolower($slug)) {
            return redirect()->to('/p/'.strtolower($slug).($request->getQueryString() === null ? '' : '?'.$request->getQueryString()), 301);
        }

        $owner = $this->cartContext->owner($request, createGuestToken: false);
        $product = $this->details->bySlug($slug, $owner?->companyId);
        abort_if($product === null, 404);

        return $this->seo($product, $owner?->companyId === null ? $product : $this->details->bySlug($slug, null))
            ->attach(Inertia::render('Storefront/Product', [
                'shell' => fn () => $this->shell->props($owner),
                'product' => $product,
            ]));
    }

    /**
     * @param  array<string, mixed>  $product  as the viewer sees it
     * @param  array<string, mixed>|null  $guestView  the same product resolved with no company
     */
    private function seo(array $product, ?array $guestView): SeoHead
    {
        $brand = Branding::current();
        $url = SeoHead::url('/p/'.$product['slug']);
        /** @var list<array{name: string, slug: string}> $crumbs */
        $crumbs = $product['breadcrumb'];
        /** @var list<array{url: string}> $images */
        $images = $product['images'];

        $trail = [['name' => 'Home', 'url' => SeoHead::url('/')]];
        foreach ($crumbs as $crumb) {
            $trail[] = ['name' => $crumb['name'], 'url' => SeoHead::url('/c/'.$crumb['slug'])];
        }
        $trail[] = ['name' => (string) $product['name'], 'url' => $url];

        $jsonLd = [StructuredData::breadcrumb($trail)];
        if ($guestView !== null) {
            /** @var list<array{sku_code: string}> $variants */
            $variants = $guestView['variants'];
            $barcode = count($variants) === 1
                ? DB::table('skus')->where('sku_code', $variants[0]['sku_code'])->value('barcode_ean')
                : null;
            array_unshift($jsonLd, StructuredData::product($guestView, $url, $brand, is_string($barcode) ? $barcode : null));
        }

        $title = is_string($product['meta_title'] ?? null) && trim($product['meta_title']) !== '' ? trim($product['meta_title']) : (string) $product['name'];

        return new SeoHead(
            title: $title.' · '.$brand->name,
            description: SeoHead::describe(
                is_string($product['meta_description'] ?? null) ? $product['meta_description'] : null,
                is_string($product['short_description'] ?? null) ? $product['short_description'] : null,
            ),
            canonical: $url,
            ogType: 'product',
            ogImage: $images === [] ? null : (str_starts_with($images[0]['url'], 'http') ? $images[0]['url'] : SeoHead::url($images[0]['url'])),
            jsonLd: $jsonLd,
        );
    }
}
