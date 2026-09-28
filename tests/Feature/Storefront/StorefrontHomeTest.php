<?php

use App\Domain\Inventory\StockLabels;
use App\Domain\Storefront\Branding;
use App\Domain\Storefront\Colour;
use App\Domain\Storefront\StorefrontSettings;
use App\Http\Support\PriceDisplay;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\SkuCost;
use App\Models\StockLevel;
use App\Models\SystemConfiguration;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * 05.15 S2 — the storefront shell, branding, price display and home page.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->location = Location::factory()->default()->create();
    $this->baseList = PriceList::factory()->create(['scope' => 'base']);
    $this->taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($this->taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
});

/** An active, base-priced, GB-taxed product with one SKU and a cost row (so "no cost" is not vacuous). */
function storefrontProduct(string $name, int $unitPriceE4 = 10000, int $onHand = 500, array $productAttributes = [], array $stockAttributes = []): Product
{
    // No factory category: it would add a random department to the menu.
    $product = Product::factory()->create(['name' => $name, 'slug' => Str::slug($name)] + $productAttributes + ['primary_category_id' => null]);
    $sku = Sku::factory()->create(['product_id' => $product->id, 'tax_class_id' => test()->taxClass->id]);
    Pack::factory()->for($sku)->create(['base_units' => 1]);
    PriceListItem::factory()->for(test()->baseList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => $unitPriceE4]);
    StockLevel::factory()->for($sku)->for(test()->location)->create(['on_hand_base_qty' => $onHand, 'allocated_base_qty' => 0] + $stockAttributes);
    SkuCost::factory()->for($sku)->create();

    return $product;
}

function storefrontAdmin(): User
{
    $admin = User::factory()->withTwoFactor()->create();
    $role = Role::query()->where('code', 'admin')->first() ?? Role::factory()->create(['code' => 'admin']);
    RoleUser::create(['role_id' => $role->id, 'user_id' => $admin->id]);

    return $admin;
}

it('renders the home page with the brand, departments and priced product cards, and no stock figures or cost', function () {
    SystemConfiguration::factory()->create(['config_key' => Branding::NAME, 'value_type' => 'text', 'value_int' => null, 'value_text' => 'Quay Stores']);
    $root = Category::factory()->create(['name' => 'Kitchen', 'slug' => 'kitchen', 'depth' => 0]);
    Category::factory()->childOf($root->id)->create(['name' => 'Cookware', 'slug' => 'cookware']);
    storefrontProduct('Frying Pan', unitPriceE4: 85000);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Storefront/Home', false)
        ->where('brand.name', 'Quay Stores')
        ->where('brand.show_powered_by', true)
        ->where('price_display', ['mode' => 'gross', 'can_switch' => true])
        ->where('shell.categories.0.slug', 'kitchen')
        ->where('shell.categories.0.children.0.slug', 'cookware')
        ->where('shell.cart_count', 0)
        ->where('products.0.name', 'Frying Pan')
        ->where('products.0.price', ['unit_net_e4' => 85000, 'tax_rate_bp' => 2000, 'varies' => false])
        ->where('products.0.stock', 'in_stock')
        ->where('products.0', fn ($card) => ! collect($card)->keys()->intersect(['unit_cost_e4', 'available_base_qty', 'on_hand_base_qty'])->count()));
});

it('lists featured products first, then the most recently published', function () {
    storefrontProduct('Old Plain', productAttributes: ['published_at' => now()->subDays(10)]);
    storefrontProduct('New Plain', productAttributes: ['published_at' => now()->subDay()]);
    storefrontProduct('Old Featured', productAttributes: ['published_at' => now()->subDays(20), 'is_featured' => true]);
    storefrontProduct('Draft', productAttributes: ['status' => 'draft']);

    $names = collect($this->get('/')->viewData('page')['props']['products'])->pluck('name')->all();

    expect($names)->toBe(['Old Featured', 'New Plain', 'Old Plain']);
});

it('labels stock without revealing figures', function () {
    $in = storefrontProduct('Plenty', onHand: 500)->skus()->sole();
    $low = storefrontProduct('Few', onHand: 10, stockAttributes: ['reorder_point_base_qty' => 20])->skus()->sole();
    $out = storefrontProduct('None', onHand: 0)->skus()->sole();
    $backorder = storefrontProduct('Backorder', onHand: 0)->skus()->sole();
    $backorder->forceFill(['allow_backorder' => true])->save();
    $untracked = storefrontProduct('Untracked', onHand: 0)->skus()->sole();
    $untracked->forceFill(['is_stock_tracked' => false])->save();

    expect((new StockLabels)->forSkus([$in->id, $low->id, $out->id, $backorder->id, $untracked->id]))->toBe([
        $in->id => 'in_stock', $low->id => 'low_stock', $out->id => 'out_of_stock', $backorder->id => 'backorder', $untracked->id => 'in_stock',
    ])->and(StockLabels::best(['out_of_stock', 'low_stock', 'backorder']))->toBe('low_stock');
});

it('lets guests and public customers switch to ex VAT, but never at checkout', function () {
    $this->post('/price-display', ['mode' => 'net'])->assertRedirect()->assertCookie(PriceDisplay::COOKIE, 'net');
    $this->withCookie(PriceDisplay::COOKIE, 'net')->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('price_display.mode', 'net'));

    $public = User::factory()->create();
    $this->actingAs($public)->withCookie(PriceDisplay::COOKIE, 'net')->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('price_display', ['mode' => 'net', 'can_switch' => true]));
    // A consumer always sees the VAT-inclusive total at checkout.
    expect($this->actingAs($public)->withCookie(PriceDisplay::COOKIE, 'net')->get('/checkout')->viewData('page')['props']['display_mode'])->toBe('gross');

    $this->post('/price-display', ['mode' => 'both'])->assertSessionHasErrors('mode');
});

it('keeps trade users on their company setting and staff on ex VAT, without a switch', function () {
    $company = Company::factory()->create(['price_display_mode' => 'net']);
    $buyer = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $buyer->id]);

    $this->actingAs($buyer)->withCookie(PriceDisplay::COOKIE, 'gross')->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('price_display', ['mode' => 'net', 'can_switch' => false]));
    $this->actingAs($buyer)->post('/price-display', ['mode' => 'gross'])->assertForbidden();

    $this->actingAs(storefrontAdmin())->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('price_display', ['mode' => 'net', 'can_switch' => false]));
});

it('falls back to the app name and shows the product credit when nothing is configured', function () {
    $brand = Branding::current()->toArray();

    expect($brand['name'])->toBe(config('app.name'))
        ->and($brand['show_powered_by'])->toBeTrue()
        ->and($brand['primary_hsl'])->toBeNull()
        ->and($brand['logo_url'])->toBeNull();
});

it('saves storefront settings for an admin and audits only what changed', function () {
    $admin = storefrontAdmin();
    $settings = new StorefrontSettings;
    $input = ['name' => 'Quay Stores', 'tagline' => 'Trade and retail', 'logo_path' => null, 'primary_colour' => '#1d4ed8',
        'support_email' => 'hello@quay.example', 'support_phone' => '020 7946 0000', 'show_powered_by' => true];

    $settings->save($admin, $input);
    $settings->save($admin, ['tagline' => '', 'show_powered_by' => false] + $input);

    $brand = Branding::current();
    expect($brand->name)->toBe('Quay Stores')
        ->and($brand->tagline)->toBeNull()
        ->and($brand->showPoweredBy)->toBeFalse()
        ->and(SystemConfiguration::query()->where('config_key', Branding::TAGLINE)->exists())->toBeFalse();

    $audits = AuditLog::query()->where('action', 'configuration.storefront_settings_changed')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->actor_user_id)->toBe($admin->id)
        ->and($audits[0]->after)->toEqual(['brand.name' => 'Quay Stores', 'brand.tagline' => 'Trade and retail', 'brand.primary_colour' => '#1d4ed8',
            'brand.support_email' => 'hello@quay.example', 'brand.support_phone' => '020 7946 0000'])
        ->and($audits[1]->before)->toEqual(['brand.tagline' => 'Trade and retail', 'brand.show_powered_by' => true])
        ->and($audits[1]->after)->toEqual(['brand.tagline' => null, 'brand.show_powered_by' => false]);

    // Saving the same values again changes nothing and writes no audit row.
    $settings->save($admin, ['tagline' => '', 'show_powered_by' => false] + $input);
    expect(AuditLog::query()->where('action', 'configuration.storefront_settings_changed')->count())->toBe(2);
});

it('refuses a brand colour too light for white text, and non-admins', function () {
    $input = ['name' => 'Quay Stores', 'primary_colour' => '#fde047', 'show_powered_by' => true];

    expect(fn () => (new StorefrontSettings)->save(storefrontAdmin(), $input))->toThrow(ValidationException::class);

    $warehouse = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => Role::factory()->create(['code' => 'warehouse'])->id, 'user_id' => $warehouse->id]);
    expect(fn () => (new StorefrontSettings)->save($warehouse, ['primary_colour' => '#1d4ed8'] + $input))->toThrow(AuthorizationException::class);

    $this->actingAs($warehouse)->get('/admin/settings/storefront')->assertForbidden();
    expect(SystemConfiguration::query()->where('config_key', 'like', 'brand.%')->exists())->toBeFalse();
});

it('computes WCAG contrast and HSL channels for brand colours', function () {
    expect(Colour::contrast('#ffffff', '#000000'))->toEqualWithDelta(21.0, 0.01)
        ->and(Colour::contrast('#1d4ed8', '#ffffff'))->toBeGreaterThan(4.5)
        ->and(Colour::contrast('#fde047', '#ffffff'))->toBeLessThan(4.5)
        ->and(Colour::hslChannels('#1d4ed8'))->toBe('224 76% 48%')
        ->and(Colour::hslChannels('#808080'))->toBe('0 0% 50%')
        ->and(Colour::readableOn('#1d4ed8'))->toBe('#ffffff')
        ->and(Colour::readableOn('#fde047'))->toBe('#0a0a0b');
});
