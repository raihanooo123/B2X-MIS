<?php

namespace Database\Seeders;

use App\Domain\Catalogue\CategoryClosureMaintainer;
use App\Domain\Catalogue\CategoryPath;
use App\Domain\Pricing\Money;
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
use App\Models\SystemConfiguration;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local/demo fixture data — a working, checkout-able catalogue, not a
 * production dataset. Every table this touches is seeded through the
 * same factories the test suite uses, so anything valid enough to pass
 * a test is valid enough to seed — but every *name*, SKU code, pack
 * size and price below is written out explicitly rather than left to
 * the factories' own Faker defaults, because a demo catalogue a human
 * looks at needs to read like a real UK cash-and-carry, not Latin
 * placeholder text next to a random SKU code. The factories themselves
 * are untouched: their random defaults are exactly right for tests.
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
        $this->seedSellerDetails();

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
            'receipt_number' => 'RCP-',
            'rma_number' => 'RMA-',
            'credit_note_number' => 'CN-',
            'quote_number' => 'QT-',
            // 'po_number' is seeded by migration 2026_10_10_090100 (02 §23).
        ] as $keyName => $prefix) {
            NumberSequence::factory()->forSeries($keyName, $prefix)->create();
        }
    }

    /**
     * Doc 02 §21.3 — the supplier details a VAT invoice prints. Demo
     * placeholders, plainly not real: replace them before issuing a
     * document anyone will rely on.
     */
    private function seedSellerDetails(): void
    {
        foreach ([
            'seller.legal_name' => 'Demo Wholesale Ltd',
            'seller.address' => "1 Demo Trading Estate\nLondon\nE1 6AN",
            'seller.vat_number' => 'GB000000000',
            'seller.company_number' => '00000000',
        ] as $key => $value) {
            SystemConfiguration::factory()->create([
                'config_key' => $key,
                'value_type' => 'text',
                'value_int' => null,
                'value_text' => $value,
                'description' => 'Printed on invoices and receipts (02 §21.3).',
            ]);
        }
    }

    /**
     * Bronze/Silver/Gold — a new trade account starts at Bronze and
     * earns its way up, so Bronze is the system default.
     *
     * @return array<string, PriceTier> keyed by code
     */
    private function seedPriceTiers(): array
    {
        return [
            'bronze' => PriceTier::factory()->default()->create(['code' => 'bronze', 'name' => 'Bronze', 'position' => 1]),
            'silver' => PriceTier::factory()->create(['code' => 'silver', 'name' => 'Silver', 'position' => 2]),
            'gold' => PriceTier::factory()->create(['code' => 'gold', 'name' => 'Gold', 'position' => 3]),
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
     * Six departments a UK cash-and-carry actually runs, each with a
     * couple of real subcategories. Roots are created (and their
     * closure rows computed) before their children, because
     * CategoryClosureMaintainer::recompute() builds a child's ancestor
     * chain by reading the parent's already-existing closure rows —
     * see that class's docblock.
     *
     * @return array<string, Category> every subcategory (not the
     *                                 roots), keyed by the slug
     *                                 seedProducts() assigns products
     *                                 against
     */
    private function seedCategories(): array
    {
        $tree = [
            'Kitchenware' => [
                'cookware' => 'Cookware',
                'kitchen-utensils' => 'Kitchen Utensils',
                'food-storage' => 'Food Storage',
            ],
            'Cleaning Supplies' => [
                'household-cleaning' => 'Household Cleaning',
                'laundry' => 'Laundry',
            ],
            'Storage' => [
                'storage-boxes' => 'Storage Boxes',
            ],
            'Bathroom' => [
                'bathroom-accessories' => 'Bathroom Accessories',
            ],
            'Stationery' => [
                'office-supplies' => 'Office Supplies',
                'writing-instruments' => 'Writing Instruments',
            ],
            'Party Supplies' => [
                'party-tableware' => 'Party Tableware',
            ],
        ];

        $maintainer = new CategoryClosureMaintainer;
        $subcategories = [];

        foreach ($tree as $rootName => $children) {
            $root = Category::factory()->create([
                'name' => $rootName,
                'slug' => Str::slug($rootName),
                'depth' => 0,
            ]);
            $root->update(['path' => CategoryPath::build(null, $root->id)]);
            $maintainer->recompute($root);

            foreach ($children as $key => $childName) {
                $child = Category::factory()->childOf($root->id)->create([
                    'name' => $childName,
                    'slug' => Str::slug($childName),
                ]);
                $child->update(['path' => CategoryPath::build($root->path, $child->id)]);
                $maintainer->recompute($child);
                $subcategories[$key] = $child;
            }
        }

        return $subcategories;
    }

    /**
     * Seven wholesale house brands, each scoped to the department(s) it
     * plausibly supplies — invented names, not real trademarks, same
     * reasoning `BrandFactory` uses `fake()->company()` for, just
     * chosen deliberately instead of at random.
     *
     * @return array<string, Brand> keyed by the short key seedProducts()
     *                              assigns products against
     */
    private function seedBrands(): array
    {
        $brands = [
            'lonsdale' => ['name' => 'Lonsdale Housewares', 'description' => 'Cookware and kitchen essentials for trade and retail.'],
            'primeclean' => ['name' => 'PrimeClean', 'description' => 'Household and laundry cleaning products.'],
            'yorkshire-paper' => ['name' => 'Yorkshire Paper Co', 'description' => 'Paper, foil and food-wrap essentials.'],
            'kingfisher' => ['name' => 'Kingfisher Storage', 'description' => 'Practical storage solutions for home and office.'],
            'clearline' => ['name' => 'Clearline Bathroom', 'description' => 'Bathroom accessories built to last.'],
            'foxglove' => ['name' => 'Foxglove Stationery', 'description' => 'Office and school stationery essentials.'],
            'bristol-party' => ['name' => 'Bristol Party Co', 'description' => 'Disposable tableware and party essentials.'],
        ];

        $created = [];

        foreach ($brands as $key => $brand) {
            $created[$key] = Brand::factory()->create([
                'name' => $brand['name'],
                'slug' => Str::slug($brand['name']),
                'description' => $brand['description'],
            ]);
        }

        return $created;
    }

    /**
     * The catalogue itself: 51 products a UK cash-and-carry would
     * actually stock, one SKU each, real pack economics (each / inner
     * case / outer case, sized per department rather than one-size-
     * fits-all), and a three-rung break table on the shared base price
     * list. `price_e4` and the pack sizes are hand-picked per product —
     * see the class docblock for why none of this comes from Faker.
     *
     * @param  array<string, Category>  $categories  keyed by subcategory slug
     * @param  array<string, Brand>  $brands  keyed by brand key
     */
    private function seedProducts(array $categories, array $brands, TaxClass $standardTaxClass, PriceList $basePriceList, Location $location): void
    {
        // [inner case qty, outer case qty] per subcategory — small,
        // cheap lines (pens, tableware multipacks) sell in bigger cases
        // than bulky items (storage boxes) do.
        $packProfiles = [
            'cookware' => [6, 24],
            'kitchen-utensils' => [12, 144],
            'food-storage' => [12, 144],
            'household-cleaning' => [6, 24],
            'laundry' => [6, 24],
            'storage-boxes' => [4, 16],
            'bathroom-accessories' => [6, 24],
            'office-supplies' => [10, 100],
            'writing-instruments' => [12, 144],
            'party-tableware' => [12, 144],
        ];

        $descriptions = [
            'cookware' => 'Durable cookware for everyday trade and retail sale.',
            'kitchen-utensils' => 'Everyday kitchen utensils built for regular use.',
            'food-storage' => 'Reliable food storage and wrap for the kitchen.',
            'household-cleaning' => 'Effective household cleaning for trade and retail.',
            'laundry' => 'Everyday laundry care essentials.',
            'storage-boxes' => 'Sturdy storage for home, garage and office.',
            'bathroom-accessories' => 'Practical bathroom accessories built to last.',
            'office-supplies' => 'Office essentials for everyday business use.',
            'writing-instruments' => 'Reliable writing instruments for office and school.',
            'party-tableware' => 'Disposable tableware for parties and events.',
        ];

        // [sku, name, category key, brand key, price in £e4]
        $products = [
            ['KIT-FRY24', 'Non-Stick Frying Pan 24cm', 'cookware', 'lonsdale', 85000],
            ['KIT-SAU18', 'Stainless Steel Saucepan 18cm', 'cookware', 'lonsdale', 67500],
            ['KIT-CAS4L', 'Cast Iron Casserole Dish 4L', 'cookware', 'lonsdale', 149900],
            ['KIT-BAK01', 'Non-Stick Baking Tray', 'cookware', 'lonsdale', 32500],
            ['KIT-STP10', 'Stock Pot 10L', 'cookware', 'lonsdale', 165000],

            ['KIT-UTS05', 'Stainless Steel Utensil Set 5pc', 'kitchen-utensils', 'lonsdale', 49900],
            ['KIT-SPA01', 'Silicone Spatula', 'kitchen-utensils', 'lonsdale', 17500],
            ['KIT-WSP03', 'Wooden Spoon Set 3pc', 'kitchen-utensils', 'lonsdale', 22500],
            ['KIT-PEE01', 'Vegetable Peeler', 'kitchen-utensils', 'lonsdale', 9500],
            ['KIT-SCI01', 'Kitchen Scissors', 'kitchen-utensils', 'lonsdale', 25000],

            ['KIT-FST15', 'Airtight Food Container 1.5L', 'food-storage', 'kingfisher', 35000],
            ['KIT-JAR03', 'Glass Storage Jar Set 3pc', 'food-storage', 'kingfisher', 62500],
            ['KIT-CLF300', 'Cling Film 300m', 'food-storage', 'yorkshire-paper', 18500],
            ['KIT-FOI45', 'Aluminium Foil 45m', 'food-storage', 'yorkshire-paper', 21500],
            ['KIT-SAB100', 'Sandwich Bags 100pk', 'food-storage', 'yorkshire-paper', 12000],

            ['CLN-MSC750', 'Multi-Surface Cleaner Spray 750ml', 'household-cleaning', 'primeclean', 11000],
            ['CLN-BAC750', 'Bathroom Cleaner 750ml', 'household-cleaning', 'primeclean', 12500],
            ['CLN-GLC500', 'Glass Cleaner 500ml', 'household-cleaning', 'primeclean', 9500],
            ['CLN-MFC10', 'Microfibre Cloths 10pk', 'household-cleaning', 'primeclean', 27500],
            ['CLN-RGL01', 'Rubber Gloves', 'household-cleaning', 'primeclean', 8500],
            ['CLN-MOP01', 'Mop and Bucket Set', 'household-cleaning', 'primeclean', 95000],

            ['CLN-LDT100', 'Laundry Detergent 100 Wash', 'laundry', 'primeclean', 69900],
            ['CLN-FSO2L', 'Fabric Softener 2L', 'laundry', 'primeclean', 23500],
            ['CLN-STR500', 'Stain Remover Spray 500ml', 'laundry', 'primeclean', 16500],
            ['CLN-LBM01', 'Laundry Bags Mesh', 'laundry', 'primeclean', 14000],

            ['STO-BOX35', 'Clear Plastic Storage Box 35L', 'storage-boxes', 'kingfisher', 55000],
            ['STO-BOX60', 'Under-Bed Storage Box 60L', 'storage-boxes', 'kingfisher', 72500],
            ['STO-CRA20', 'Stackable Storage Crate 20L', 'storage-boxes', 'kingfisher', 41000],
            ['STO-BOX10', 'Storage Box with Lid 10L', 'storage-boxes', 'kingfisher', 26000],
            ['STO-VAC03', 'Vacuum Storage Bags 3pk', 'storage-boxes', 'kingfisher', 37500],

            ['BTH-BIN05', 'Bathroom Bin 5L', 'bathroom-accessories', 'clearline', 21000],
            ['BTH-TBH01', 'Toilet Brush and Holder', 'bathroom-accessories', 'clearline', 24000],
            ['BTH-SDI01', 'Soap Dispenser', 'bathroom-accessories', 'clearline', 15500],
            ['BTH-MAT01', 'Bath Mat Non-Slip', 'bathroom-accessories', 'clearline', 42500],
            ['BTH-CAD01', 'Shower Caddy', 'bathroom-accessories', 'clearline', 36000],

            ['STA-A4P500', 'A4 Copier Paper 500 Sheets', 'office-supplies', 'foxglove', 31000],
            ['STA-RBA401', 'Ring Binder A4', 'office-supplies', 'foxglove', 14500],
            ['STA-LAF01', 'Lever Arch File', 'office-supplies', 'foxglove', 19000],
            ['STA-STN12', 'Sticky Notes 3x3 12pk', 'office-supplies', 'foxglove', 22000],
            ['STA-STP01', 'Stapler Standard', 'office-supplies', 'foxglove', 17500],
            ['STA-PCL100', 'Paper Clips 100pk', 'office-supplies', 'foxglove', 6500],

            ['STA-BPP50', 'Ballpoint Pens 50pk', 'writing-instruments', 'foxglove', 34000],
            ['STA-PMK12', 'Permanent Markers 12pk', 'writing-instruments', 'foxglove', 46000],
            ['STA-PEN12', 'Pencils HB 12pk', 'writing-instruments', 'foxglove', 11000],
            ['STA-HLT06', 'Highlighters 6pk', 'writing-instruments', 'foxglove', 20500],

            ['PTY-PLT50', 'Disposable Plates 8in 50pk', 'party-tableware', 'bristol-party', 27500],
            ['PTY-NAP40', 'Paper Napkins 3-ply 40pk', 'party-tableware', 'bristol-party', 16000],
            ['PTY-CUP50', 'Plastic Cups 200ml 50pk', 'party-tableware', 'bristol-party', 21000],
            ['PTY-TBC01', 'Party Tablecloth', 'party-tableware', 'bristol-party', 13000],
            ['PTY-CUT50', 'Disposable Cutlery Set 50pk', 'party-tableware', 'bristol-party', 32000],
            ['PTY-BAL100', 'Balloons 100pk', 'party-tableware', 'bristol-party', 24500],
        ];

        foreach ($products as [$skuCode, $name, $categoryKey, $brandKey, $unitPriceE4]) {
            [$innerQty, $outerQty] = $packProfiles[$categoryKey];
            $description = $descriptions[$categoryKey];

            $product = Product::factory()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'brand_id' => $brands[$brandKey]->id,
                'primary_category_id' => $categories[$categoryKey]->id,
                'short_description' => $description,
                'description' => $description,
            ]);

            // 05.6 §5.1: carriage is rated from pack weights. A stable
            // 100–900 g per unit from the SKU code; packs add packaging
            // (inner 50 g, outer 250 g); outers stack 8 per layer, 5 layers.
            $unitWeightG = 100 + (crc32($skuCode) % 801);

            $sku = Sku::factory()->create([
                'product_id' => $product->id,
                'sku_code' => $skuCode,
                'tax_class_id' => $standardTaxClass->id,
                'unit_weight_g' => $unitWeightG,
            ]);

            $each = Pack::factory()->for($sku)->create([
                'code' => 'EACH',
                'label' => 'Each',
                'pack_level' => 'each',
                'base_units' => 1,
                'is_default_sell' => true,
                'gross_weight_g' => $unitWeightG,
            ]);
            Pack::factory()->for($sku)->create([
                'code' => "INNER{$innerQty}",
                'label' => "Inner of {$innerQty}",
                'pack_level' => 'inner',
                'base_units' => $innerQty,
                'is_default_sell' => false,
                'gross_weight_g' => $innerQty * $unitWeightG + 50,
            ]);
            Pack::factory()->for($sku)->outer($outerQty)->create([
                'gross_weight_g' => $outerQty * $unitWeightG + 250,
                'packs_per_layer' => 8,
                'layers_per_pallet' => 5,
            ]);

            // Resolves the skus <-> packs circular FK (02 §5.5): the SKU
            // is created first with default_pack_id null, updated once
            // its default-sell pack exists.
            $sku->update(['default_pack_id' => $each->id]);

            StockLevel::factory()->for($sku)->for($location)->create([
                'on_hand_base_qty' => fake()->numberBetween(100, 2000),
            ]);

            // Break pricing: 5% off at the inner case, 10% off at the
            // outer case — integer-only (CLAUDE.md invariant 1):
            // Money::roundHalfUpDiv() on an already-integer numerator,
            // never a float multiplication.
            $innerPriceE4 = Money::roundHalfUpDiv($unitPriceE4 * 95, 100);
            $outerPriceE4 = Money::roundHalfUpDiv($unitPriceE4 * 90, 100);

            PriceListItem::factory()->for($basePriceList, 'priceList')->for($sku)->create([
                'min_base_qty' => 1,
                'unit_price_e4' => $unitPriceE4,
            ]);
            PriceListItem::factory()->for($basePriceList, 'priceList')->for($sku)->create([
                'min_base_qty' => $innerQty,
                'unit_price_e4' => $innerPriceE4,
            ]);
            PriceListItem::factory()->for($basePriceList, 'priceList')->for($sku)->create([
                'min_base_qty' => $outerQty,
                'unit_price_e4' => $outerPriceE4,
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
            'price_tier_id' => $tiers['gold']->id,
            'payment_terms' => 'net30',
            'credit_limit_minor' => 2000000,
        ]);

        Company::factory()->create([
            'name' => 'Coastal Retail Group',
            'price_tier_id' => $tiers['silver']->id,
            'payment_terms' => 'net14',
            'credit_limit_minor' => 750000,
        ]);

        Company::factory()->create([
            'name' => 'Summit Trading Co',
            'price_tier_id' => $tiers['bronze']->id,
            'payment_terms' => 'prepay',
            'credit_limit_minor' => 500000,
        ]);
    }
}
