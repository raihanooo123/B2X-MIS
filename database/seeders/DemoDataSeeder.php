<?php

namespace Database\Seeders;

use App\Domain\Catalogue\CategoryClosureMaintainer;
use App\Domain\Catalogue\CategoryPath;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local/demo fixture data — a working, checkout-able catalogue, not a
 * production dataset. Every table this touches is seeded through the
 * same factories the test suite uses, so anything valid enough to pass
 * a test is valid enough to seed (and vice versa: a factory change that
 * breaks a constraint breaks this seeder the same way).
 *
 * Run order matters in a few places for real reasons, not just
 * tidiness: roles before the admin user (role_user needs both rows),
 * tax classes before SKUs (skus.tax_class_id is NOT NULL), the base
 * price list before price_list_items (price_lists_no_base_overlap
 * allows exactly one active base-scope list per currency, so it is
 * created once and shared across every SKU rather than per-product),
 * category roots before their children (CategoryClosureMaintainer reads
 * the parent's existing closure rows to build the child's ancestor
 * chain — see its own docblock), and packs before
 * `skus.default_pack_id` is set (the skus/packs FK is circular,
 * resolved by creating the SKU first with it null, then updating it
 * once the pack exists — 02 §5.5).
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $roles = $this->seedRoles();
        $this->seedAdminUser($roles['admin']);
        $this->seedNumberSequences();

        $tiers = $this->seedPriceTiers();
        $taxClasses = $this->seedTaxClasses();
        $location = $this->seedLocation();
        $categories = $this->seedCategories();
        $brands = $this->seedBrands();

        $basePriceList = PriceList::factory()->create([
            'code' => 'base-gbp',
            'name' => 'Base GBP Price List',
            'scope' => 'base',
        ]);

        $this->seedProducts($categories, $brands, $taxClasses['standard'], $basePriceList, $location);
        $this->seedCompanies($tiers);

        $this->command?->info('Demo data seeded. Admin login: admin@example.com / password');
    }

    /**
     * Doc 02 §14.1 — the six-value closed role list, `roles_code_chk`.
     *
     * @return array<string, Role> keyed by role code
     */
    private function seedRoles(): array
    {
        $roles = [];

        foreach ([
            'admin' => 'Administrator',
            'accounts' => 'Accounts',
            'purchasing' => 'Purchasing',
            'rep' => 'Sales Rep',
            'warehouse' => 'Warehouse',
            'sales_manager' => 'Sales Manager',
        ] as $code => $name) {
            $roles[$code] = Role::factory()->create(['code' => $code, 'name' => $name]);
        }

        return $roles;
    }

    /**
     * `RoleUser::create()`, never `->attach()` — Role::users()'s own
     * docblock explains why attach() fails against role_user's real
     * schema (created_at only, no updated_at).
     */
    private function seedAdminUser(Role $adminRole): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'first_name' => 'Demo',
            'last_name' => 'Admin',
        ]);

        RoleUser::create(['role_id' => $adminRole->id, 'user_id' => $admin->id]);
    }

    /**
     * Doc 02 §11.3 — every document series named there, provisioned up
     * front. Without these rows, NumberSequenceService::next() throws
     * UnknownNumberSequenceException the first time anything (checkout,
     * invoicing, ...) tries to issue a document number against this
     * seeded data.
     */
    private function seedNumberSequences(): void
    {
        foreach ([
            'order_number' => 'SO-',
            'invoice_number' => 'INV-',
            'rma_number' => 'RMA-',
            'credit_note_number' => 'CN-',
            'quote_number' => 'QT-',
            'po_number' => 'PO-',
        ] as $keyName => $prefix) {
            NumberSequence::factory()->forSeries($keyName, $prefix)->create();
        }
    }

    /**
     * @return array<string, PriceTier> keyed by code
     */
    private function seedPriceTiers(): array
    {
        return [
            'trade' => PriceTier::factory()->create(['code' => 'trade', 'name' => 'Trade', 'position' => 1]),
            'standard' => PriceTier::factory()->default()->create(['code' => 'standard', 'name' => 'Standard', 'position' => 2]),
            'premium' => PriceTier::factory()->create(['code' => 'premium', 'name' => 'Premium', 'position' => 3]),
        ];
    }

    /**
     * Doc 02 §6.5 seed: standard 2000bp, zero 0bp, reduced 500bp — the
     * exact figures the doc itself specifies, not arbitrary demo values.
     *
     * @return array<string, TaxClass> keyed by code
     */
    private function seedTaxClasses(): array
    {
        $standard = TaxClass::factory()->standard()->create();
        TaxRate::factory()->for($standard)->standard()->create(['country_code' => 'GB']);

        $zero = TaxClass::factory()->zero()->create();
        TaxRate::factory()->for($zero)->zeroRated()->create(['country_code' => 'GB']);

        $reduced = TaxClass::factory()->reduced()->create();
        TaxRate::factory()->for($reduced)->reduced()->create(['country_code' => 'GB']);

        return ['standard' => $standard, 'zero' => $zero, 'reduced' => $reduced];
    }

    /**
     * Doc 02 §7.3 — single-warehouse launch topology. CheckoutService
     * resolves this row directly (`Location::where('is_default', true)`),
     * so without it the seeded catalogue cannot actually be checked out.
     */
    private function seedLocation(): Location
    {
        return Location::factory()->default()->create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
        ]);
    }

    /**
     * A two-level tree, ~10 categories: 4 roots, 6 children. Roots are
     * created (and their closure rows computed) before their children,
     * because CategoryClosureMaintainer::recompute() builds a child's
     * ancestor chain by reading the parent's already-existing closure
     * rows — see that class's docblock.
     *
     * @return list<Category> every category, roots and children, for
     *                        Product::primary_category_id to pick from
     */
    private function seedCategories(): array
    {
        $tree = [
            'Grocery' => ['Confectionery', 'Beverages'],
            'Household' => ['Cleaning', 'Paper Goods'],
            'Health & Beauty' => ['Personal Care'],
            'Toys & Gifts' => ['Seasonal'],
        ];

        $maintainer = new CategoryClosureMaintainer;
        $categories = [];

        foreach ($tree as $rootName => $childNames) {
            $root = Category::factory()->create([
                'name' => $rootName,
                'slug' => Str::slug($rootName),
                'depth' => 0,
            ]);
            $root->update(['path' => CategoryPath::build(null, $root->id)]);
            $maintainer->recompute($root);
            $categories[] = $root;

            foreach ($childNames as $childName) {
                $child = Category::factory()->childOf($root->id)->create([
                    'name' => $childName,
                    'slug' => Str::slug($childName),
                ]);
                $child->update(['path' => CategoryPath::build($root->path, $child->id)]);
                $maintainer->recompute($child);
                $categories[] = $child;
            }
        }

        return $categories;
    }

    /**
     * @return list<Brand>
     */
    private function seedBrands(): array
    {
        return Brand::factory()->count(5)->create()->all();
    }

    /**
     * ~50 wholesale products, each with one SKU, three packs (each /
     * inner-of-6 / outer-of-24 — 02 §5.6: a pack is a transaction unit,
     * never a storage unit), stock on hand, and a three-rung break
     * table (1 / 6 / 24 base units) on the shared base price list.
     *
     * @param  list<Category>  $categories
     * @param  list<Brand>  $brands
     */
    private function seedProducts(array $categories, array $brands, TaxClass $standardTaxClass, PriceList $basePriceList, Location $location): void
    {
        for ($i = 0; $i < 50; $i++) {
            $product = Product::factory()
                ->withBrand($brands[array_rand($brands)])
                ->create(['primary_category_id' => $categories[array_rand($categories)]->id]);

            $sku = Sku::factory()->create([
                'product_id' => $product->id,
                'tax_class_id' => $standardTaxClass->id,
            ]);

            $each = Pack::factory()->for($sku)->create([
                'code' => 'EACH',
                'label' => 'Each',
                'pack_level' => 'each',
                'base_units' => 1,
                'is_default_sell' => true,
            ]);
            Pack::factory()->for($sku)->create([
                'code' => 'INNER6',
                'label' => 'Inner of 6',
                'pack_level' => 'inner',
                'base_units' => 6,
                'is_default_sell' => false,
            ]);
            Pack::factory()->for($sku)->outer(24)->create();

            // Resolves the skus <-> packs circular FK (02 §5.5): the SKU
            // is created first with default_pack_id null, updated once
            // its default-sell pack exists.
            $sku->update(['default_pack_id' => $each->id]);

            StockLevel::factory()->for($sku)->for($location)->create([
                'on_hand_base_qty' => fake()->numberBetween(100, 2000),
            ]);

            $unitPriceE4 = fake()->numberBetween(500, 50000); // £0.05 to £5.00 per base unit
            PriceListItem::factory()->for($basePriceList, 'priceList')->for($sku)->create([
                'min_base_qty' => 1,
                'unit_price_e4' => $unitPriceE4,
            ]);
            PriceListItem::factory()->for($basePriceList, 'priceList')->for($sku)->create([
                'min_base_qty' => 6,
                'unit_price_e4' => (int) round($unitPriceE4 * 0.95),
            ]);
            PriceListItem::factory()->for($basePriceList, 'priceList')->for($sku)->create([
                'min_base_qty' => 24,
                'unit_price_e4' => (int) round($unitPriceE4 * 0.90),
            ]);
        }
    }

    /**
     * @param  array<string, PriceTier>  $tiers
     */
    private function seedCompanies(array $tiers): void
    {
        Company::factory()->create([
            'name' => 'Northgate Wholesale Ltd',
            'price_tier_id' => $tiers['trade']->id,
            'payment_terms' => 'net30',
            'credit_limit_minor' => 2000000,
        ]);

        Company::factory()->create([
            'name' => 'Coastal Retail Group',
            'price_tier_id' => $tiers['standard']->id,
            'payment_terms' => 'net14',
            'credit_limit_minor' => 750000,
        ]);

        Company::factory()->create([
            'name' => 'Summit Trading Co',
            'price_tier_id' => $tiers['premium']->id,
            'payment_terms' => 'prepay',
            'credit_limit_minor' => 500000,
        ]);
    }
}
