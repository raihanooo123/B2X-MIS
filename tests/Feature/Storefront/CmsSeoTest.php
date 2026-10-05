<?php

use App\Domain\Accounts\TermsKind;
use App\Domain\Accounts\TermsPublisher;
use App\Domain\Cms\CmsPublisher;
use App\Domain\Cms\CookieRegistry;
use App\Domain\Cms\CurrentPages;
use App\Domain\Cms\PageKey;
use App\Domain\Cms\SafeMarkdown;
use App\Domain\Cms\SearchIndexing;
use App\Domain\Seo\SeoHead;
use App\Domain\Seo\StructuredData;
use App\Domain\Storefront\StorefrontSettings;
use App\Filament\Resources\CmsPageResource;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\SystemConfiguration;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\TermsVersion;
use App\Models\User;
use Database\Seeders\PlaceholderCmsPagesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * 05.11 (S7) — legal and help pages, cookies, head tags, JSON-LD, sitemap
 * and robots.txt.
 */
beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->location = Location::factory()->default()->create();
    $this->baseList = PriceList::factory()->create(['scope' => 'base']);
    $this->taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($this->taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
});

function cmsAdmin(): User
{
    $admin = User::factory()->withTwoFactor()->create();
    $role = Role::query()->where('code', 'admin')->first() ?? Role::factory()->create(['code' => 'admin']);
    RoleUser::create(['role_id' => $role->id, 'user_id' => $admin->id]);

    return $admin;
}

function publishPage(PageKey $key, string $body = 'Some **text**.', ?string $title = null, ?DateTimeInterface $effective = null): CmsPageVersion
{
    $admin = cmsAdmin();
    app(CmsPublisher::class)->saveDraft($admin, $key, $title ?? $key->label(), null, $body);

    return app(CmsPublisher::class)->publish($admin, $key, $effective);
}

/** An active, base-priced, GB-taxed product with one SKU. */
function seoProduct(string $name, int $unitPriceE4 = 10000, array $productAttributes = [], array $skuAttributes = []): Product
{
    // Overrides first: PHP's + keeps the left-hand value for a repeated key.
    $product = Product::factory()->create($productAttributes + ['name' => $name, 'slug' => Str::slug($name), 'primary_category_id' => null, 'published_at' => now()->subDay()]);
    $sku = Sku::factory()->create(['product_id' => $product->id, 'tax_class_id' => test()->taxClass->id] + $skuAttributes);
    Pack::factory()->for($sku)->create(['base_units' => 1]);
    PriceListItem::factory()->for(test()->baseList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => $unitPriceE4]);
    StockLevel::factory()->for($sku)->for(test()->location)->create(['on_hand_base_qty' => 500, 'allocated_base_qty' => 0]);

    return $product;
}

/** Production with indexing switched on (05.11 §4.4). */
function indexingOn(): void
{
    app()['env'] = 'production';
    SystemConfiguration::factory()->create(['config_key' => SearchIndexing::KEY, 'value_type' => 'bool', 'value_int' => 1, 'value_text' => null]);
}

/** @return list<array<string, mixed>> */
function jsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json" inertia="ld-\d+">(.*?)</script>#s', $html, $m);

    return array_map(fn (string $json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);
}

// --- Pages, drafts and versions (05.11 §2) ---------------------------------

it('creates the five page rows and nothing else', function () {
    expect(CmsPage::query()->orderBy('id')->pluck('page_key')->all())->toBe(['privacy', 'cookies', 'delivery', 'returns', 'contact']);

    expect(fn () => DB::table('cms_pages')->insert(['page_key' => 'about']))->toThrow(QueryException::class);
});

it('publishes a draft as version 1 with its SHA-256, audited without the text', function () {
    $version = publishPage(PageKey::Privacy, 'Our *privacy* notice.', 'Privacy notice');

    expect($version->version_no)->toBe(1)
        ->and($version->title)->toBe('Privacy notice')
        ->and($version->body_sha256)->toBe(hash('sha256', 'Our *privacy* notice.'));

    $audit = AuditLog::query()->where('action', 'content.page_published')->sole();
    expect($audit->event_family)->toBe('configuration')
        ->and($audit->subject_type)->toBe('cms_page')
        ->and($audit->after)->toMatchArray(['page_key' => 'privacy', 'version_no' => 1, 'body_sha256' => $version->body_sha256])
        ->and(json_encode($audit->after))->not->toContain('privacy* notice');

    // effective_from is stored to the second, and two versions of a page can't
    // take effect at the same moment (cms_page_versions_effective_uq).
    $this->travel(1)->seconds();
    expect(publishPage(PageKey::Privacy, 'Second text.')->version_no)->toBe(2);
});

it('never lets a published version change or disappear', function () {
    $version = publishPage(PageKey::Delivery);

    expect(fn () => DB::table('cms_page_versions')->where('id', $version->id)->update(['title' => 'Changed']))->toThrow(QueryException::class);
    expect(fn () => DB::table('cms_page_versions')->where('id', $version->id)->delete())->toThrow(QueryException::class);
});

it('lets only an administrator edit or publish, and refuses a time in the past or an empty draft', function () {
    $someone = User::factory()->create();
    expect(fn () => app(CmsPublisher::class)->saveDraft($someone, PageKey::Contact, 'Contact', null, 'Text'))->toThrow(AuthorizationException::class);

    $admin = cmsAdmin();
    expect(fn () => app(CmsPublisher::class)->publish($admin, PageKey::Contact))->toThrow(ValidationException::class);

    app(CmsPublisher::class)->saveDraft($admin, PageKey::Contact, 'Contact', null, 'Text');
    expect(fn () => app(CmsPublisher::class)->publish($admin, PageKey::Contact, now()->subHour()))->toThrow(ValidationException::class);
});

it('shows a page only once a version is in force, and a scheduled version only from its time', function () {
    $this->get('/privacy')->assertNotFound();

    publishPage(PageKey::Privacy, 'First.');
    publishPage(PageKey::Privacy, 'Second.', effective: now()->addDay());

    $this->get('/privacy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Storefront/LegalPage', false)
        ->where('page.version', '1')
        ->where('page.html', fn (string $html) => str_contains($html, 'First.'))
        ->where('shell.legal_pages', [['key' => 'privacy', 'label' => 'Privacy notice', 'path' => '/privacy']]));

    $this->travel(2)->days();
    CurrentPages::forget();
    $this->get('/privacy')->assertInertia(fn (AssertableInertia $page) => $page->where('page.version', '2'));
});

it('never outputs a script, an event-handler attribute or an unsafe link from page text', function () {
    $body = "<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n<p onclick=\"alert(1)\">Hi</p>\n\n"
        .'[bad](javascript:alert(1)) ![pic](javascript:alert(1)) [ok](https://example.com) **bold**';
    publishPage(PageKey::Delivery, $body);

    $public = $this->get('/delivery')->viewData('page')['props']['page']['html'];
    $preview = (string) CmsPageResource::render($body);

    foreach ([$public, $preview, SafeMarkdown::toHtml($body)] as $html) {
        // No markup from the source survives: no script element, no tag carrying an event handler.
        expect(preg_match('/<script\b/i', $html))->toBe(0)
            ->and(preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html))->toBe(0)
            ->and(preg_match('/(href|src)\s*=\s*"\s*javascript:/i', $html))->toBe(0)
            // It is shown as text instead, and Markdown still works.
            ->and($html)->toContain('&lt;script&gt;')
            ->and($html)->toContain('onerror=alert(1)&gt;')
            ->and($html)->toContain('<a href="https://example.com">ok</a>')
            ->and($html)->toContain('<strong>bold</strong>');
    }
});

it('adds the generated block the admin cannot edit to cookies, returns and contact', function () {
    publishPage(PageKey::Cookies);
    publishPage(PageKey::Returns);
    publishPage(PageKey::Contact);

    $this->get('/cookies')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('block.type', 'cookies')
        ->where('block.cookies', fn ($cookies) => collect($cookies)->pluck('name')->all() === CookieRegistry::names()));
    $this->get('/returns')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('block.type', 'statutory')
        ->where('block.sections', fn ($sections) => collect($sections)->pluck('heading')->all() === ['Your right to cancel', 'Returning goods', 'Faulty goods']));
    $this->get('/contact')->assertInertia(fn (AssertableInertia $page) => $page->where('block.type', 'contact'));
});

it('shows the terms of sale in force at /terms, and links it in the footer', function () {
    $this->get('/terms')->assertNotFound();

    TermsVersion::factory()->create(['kind' => TermsKind::Sale->value, 'version' => '2026-10', 'body_markdown' => 'Sale terms.', 'effective_from' => now()->subMinute()]);
    CurrentPages::forget(); // the factory bypasses TermsPublisher, which clears it

    $this->get('/terms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('page.version', '2026-10')
        ->where('shell.legal_pages.0', ['key' => 'terms', 'label' => 'Terms of sale', 'path' => '/terms']));
});

it('serves the footer links from the cache, and refreshes them when a page or the terms are published', function () {
    seoProduct('Cached Pan');
    $this->get('/p/cached-pan'); // warms the cache

    DB::enableQueryLog();
    $this->get('/p/cached-pan')->assertInertia(fn (AssertableInertia $page) => $page->where('shell.legal_pages', []));
    $queried = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'cms_page') || str_contains($sql, 'terms_versions'));
    DB::disableQueryLog();
    expect($queried)->toBeEmpty();

    publishPage(PageKey::Privacy);
    $this->get('/p/cached-pan')->assertInertia(fn (AssertableInertia $page) => $page->where('shell.legal_pages.0.key', 'privacy'));

    app(TermsPublisher::class)->publish(cmsAdmin(), TermsKind::Sale, '2026-11', 'Sale terms.');
    $this->get('/p/cached-pan')->assertInertia(fn (AssertableInertia $page) => $page->where('shell.legal_pages.0.key', 'terms'));
});

it('refuses to seed placeholder pages in production', function () {
    app()['env'] = 'production';

    expect(fn () => (new PlaceholderCmsPagesSeeder)->run())->toThrow(RuntimeException::class, 'must never run in production');
    expect(CmsPageVersion::query()->count())->toBe(0);
});

// --- Cookies (05.11 §2.5) --------------------------------------------------

it('sets no cookie that the Cookies page does not list', function () {
    $product = seoProduct('Listed Pan');
    $category = Category::factory()->create(['slug' => 'pans', 'status' => 'active']);
    publishPage(PageKey::Privacy);

    $names = [];
    foreach (['/', '/c/'.$category->slug, '/p/'.$product->slug, '/search?q=pan', '/cart', '/checkout', '/login', '/privacy', '/robots.txt'] as $path) {
        foreach ($this->get($path)->headers->getCookies() as $cookie) {
            $names[] = $cookie->getName();
        }
    }
    foreach ($this->post('/price-display', ['mode' => 'net'])->headers->getCookies() as $cookie) {
        $names[] = $cookie->getName();
    }

    expect(array_values(array_diff(array_unique($names), CookieRegistry::names())))->toBe([]);
});

// --- Head tags and robots (05.11 §4) ---------------------------------------

it('says noindex, nofollow everywhere while indexing is off, and serves no sitemap', function () {
    seoProduct('Hidden Pan');

    $this->get('/p/hidden-pan')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('<meta name="robots" content="noindex, nofollow" inertia="robots">', false);
    $this->get('/sitemap.xml')->assertNotFound();
    $this->get('/robots.txt')->assertOk()->assertSee("User-agent: *\nDisallow: /", false);
});

it('marks clean pages indexable with a canonical, and filtered, search and private pages not', function () {
    indexingOn();
    $product = seoProduct('Frying Pan');
    Category::factory()->create(['name' => 'Pans', 'slug' => 'pans', 'status' => 'active']);
    $base = rtrim((string) config('app.url'), '/');

    $this->get('/p/'.$product->slug)->assertOk()->assertHeaderMissing('X-Robots-Tag')
        ->assertSee('<meta name="robots" content="index, follow" inertia="robots">', false)
        ->assertSee('<link rel="canonical" href="'.$base.'/p/frying-pan" inertia="canonical">', false);

    $this->get('/c/pans')->assertHeaderMissing('X-Robots-Tag')->assertSee('inertia="canonical"', false);
    $this->get('/c/pans?utm_source=news')->assertHeaderMissing('X-Robots-Tag');
    $this->get('/c/pans?sort=newest')->assertHeader('X-Robots-Tag', 'noindex, follow')
        ->assertDontSee('inertia="canonical"', false);
    $this->get('/search?q=pan')->assertHeader('X-Robots-Tag', 'noindex, follow');
    $this->get('/cart')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/p/no-such-product')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('sends the same tags as the seo page prop, for client-side navigation', function () {
    indexingOn();
    seoProduct('Frying Pan');

    $this->get('/p/frying-pan')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('seo.robots', 'index, follow')
        ->where('seo.canonical', SeoHead::url('/p/frying-pan'))
        ->where('seo.og.type', 'product')
        ->where('seo.json_ld', fn ($ld) => count($ld) === 2));
});

it('redirects a slug in another case to the lower-case canonical address', function () {
    seoProduct('Frying Pan');
    Category::factory()->create(['slug' => 'pans', 'status' => 'active']);

    $this->get('/p/Frying-Pan?x=1')->assertStatus(301)->assertRedirect('/p/frying-pan?x=1');
    $this->get('/c/PANS')->assertStatus(301)->assertRedirect('/c/pans');
});

// --- Structured data (05.11 §5) --------------------------------------------

it('describes a product with the gross price the page shows, its availability and no cost or stock figure', function () {
    indexingOn();
    seoProduct('Frying Pan', unitPriceE4: 85000, skuAttributes: ['barcode_ean' => '5012345678900']);

    [$product, $breadcrumb] = jsonLd($this->get('/p/frying-pan')->getContent());

    // £8.50 net + 20% = £10.20, as the card prints it.
    expect($product['@type'])->toBe('Product')
        ->and($product['offers']['@type'])->toBe('Offer')
        ->and($product['offers']['price'])->toBe('10.20')
        ->and($product['offers']['priceCurrency'])->toBe('GBP')
        ->and($product['offers']['availability'])->toBe('https://schema.org/InStock')
        ->and($product['gtin13'])->toBe('5012345678900')
        ->and($product['offers']['hasMerchantReturnPolicy']['merchantReturnDays'])->toBe(14)
        ->and(json_encode($product))->not->toContain('cost')
        ->and($product['offers'])->not->toHaveKey('inventoryLevel')
        ->and($breadcrumb['@type'])->toBe('BreadcrumbList')
        ->and(end($breadcrumb['itemListElement'])['name'])->toBe('Frying Pan');
});

it('leaves out offers when no base price resolves, and states a minimum purchase', function () {
    indexingOn();
    $unpriced = Product::factory()->create(['name' => 'Unpriced', 'slug' => 'unpriced', 'primary_category_id' => null, 'published_at' => now()->subDay()]);
    $sku = Sku::factory()->create(['product_id' => $unpriced->id, 'tax_class_id' => $this->taxClass->id]);
    Pack::factory()->for($sku)->create(['base_units' => 1]);

    expect(jsonLd($this->get('/p/unpriced')->getContent())[0])->not->toHaveKey('offers');

    seoProduct('Minimum Pan', skuAttributes: ['moq_base_qty' => 6]);
    expect(jsonLd($this->get('/p/minimum-pan')->getContent())[0]['offers']['eligibleQuantity']['minValue'])->toBe(6);
});

it('formats gross prices with integers and validates EAN-13 check digits', function () {
    expect(StructuredData::grossPrice(85000, 2000))->toBe('10.20')
        ->and(StructuredData::grossPrice(8333, 2000))->toBe('1.00')
        ->and(StructuredData::grossPrice(10000, 0))->toBe('1.00')
        ->and(StructuredData::isEan13('5012345678900'))->toBeTrue()
        ->and(StructuredData::isEan13('5012345678901'))->toBeFalse()
        ->and(StructuredData::isEan13('501234567890'))->toBeFalse();
});

it('cannot let catalogue text close the JSON-LD script', function () {
    $seo = new SeoHead(title: 'T', jsonLd: [['name' => '</script><script>alert(1)</script>']]);

    $json = $seo->jsonLdStrings()[0];

    // Escaped in the output, so the script element can't be closed early…
    expect($seo->html())->not->toContain('</script><script>')
        ->and($json)->not->toContain('</script>')
        // …and the text itself survives a round trip.
        ->and(json_decode($json, true, 512, JSON_THROW_ON_ERROR)['name'])->toContain('</script>');
});

// --- Sitemap and robots.txt (05.11 §6) -------------------------------------

it('lists the home page, active categories, published products and pages in force in the sitemap', function () {
    indexingOn();
    seoProduct('Listed Pan');
    seoProduct('Draft Pan', productAttributes: ['status' => 'draft']);
    seoProduct('Future Pan', productAttributes: ['published_at' => now()->addDay()]);
    Category::factory()->create(['slug' => 'pans', 'status' => 'active']);
    Category::factory()->create(['slug' => 'hidden', 'status' => 'hidden']);
    publishPage(PageKey::Privacy);

    $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

    expect($xml)->toContain('<loc>'.SeoHead::url('/').'</loc>')
        ->toContain(SeoHead::url('/p/listed-pan'))
        ->toContain(SeoHead::url('/c/pans'))
        ->toContain(SeoHead::url('/privacy'))
        ->not->toContain('draft-pan')
        ->not->toContain('future-pan')
        ->not->toContain('/c/hidden');
});

it('refreshes the cached sitemap when a product changes', function () {
    indexingOn();
    seoProduct('First Pan');
    expect($this->get('/sitemap.xml')->getContent())->not->toContain('second-pan');

    seoProduct('Second Pan');

    expect($this->get('/sitemap.xml')->getContent())->toContain('second-pan');
});

it('disallows private areas in robots.txt and points at the sitemap once indexing is on', function () {
    indexingOn();

    $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Disallow: /checkout', false)
        ->assertSee('Disallow: /account', false)
        ->assertSee('Sitemap: '.SeoHead::url('/sitemap.xml'), false)
        ->assertDontSee('Disallow: /search', false);
});

// --- The switch (05.11 §4.4) -----------------------------------------------

it('saves the indexing switch from Storefront settings, audited, and leaves it alone when not sent', function () {
    $admin = cmsAdmin();
    $settings = ['name' => 'Quay Stores', 'show_powered_by' => true];

    (new StorefrontSettings)->save($admin, $settings + ['indexing_enabled' => true]);
    expect(SearchIndexing::switchedOn())->toBeTrue()
        ->and(SearchIndexing::enabled())->toBeFalse(); // not production

    (new StorefrontSettings)->save($admin, $settings);
    expect(SearchIndexing::switchedOn())->toBeTrue();

    $audit = AuditLog::query()->where('action', 'configuration.storefront_settings_changed')->latest('id')->first();
    expect(array_key_exists('seo.indexing_enabled', $audit->after ?? []))->toBeTrue()
        ->and(($audit->after ?? [])['seo.indexing_enabled'])->toBeTrue();
});
