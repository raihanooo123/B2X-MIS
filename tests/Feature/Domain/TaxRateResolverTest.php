<?php

use App\Domain\Pricing\Exceptions\NoTaxRateException;
use App\Domain\Pricing\TaxRateResolver;
use App\Models\Company;
use App\Models\Sku;
use App\Models\TaxClass;
use App\Models\TaxRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the standard 20% rate from skus.tax_class_id + country + time (03 §10)', function () {
    $taxClass = TaxClass::factory()->standard()->create();
    TaxRate::factory()->for($taxClass)->standard()->create(['country_code' => 'GB']);
    $sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);

    $rateBp = (new TaxRateResolver)->resolve($sku->id, companyId: null, countryCode: 'GB', at: CarbonImmutable::now());

    expect($rateBp)->toBe(2000);
});

it('resolves a zero-rated tax class to 0bp', function () {
    $taxClass = TaxClass::factory()->zero()->create();
    TaxRate::factory()->for($taxClass)->zeroRated()->create(['country_code' => 'GB']);
    $sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);

    $rateBp = (new TaxRateResolver)->resolve($sku->id, companyId: null, countryCode: 'GB', at: CarbonImmutable::now());

    expect($rateBp)->toBe(0);
});

it('resolves a dated VAT change correctly for orders on either side of it (03 §10: "a VAT change does not retroactively alter historical invoices")', function () {
    $taxClass = TaxClass::factory()->create();
    $changeover = CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC');

    // the old rate, closed off exactly at the changeover
    TaxRate::factory()->for($taxClass)
        ->forPeriod(CarbonImmutable::parse('2020-01-01 00:00:00', 'UTC'), $changeover)
        ->create(['country_code' => 'GB', 'rate_bp' => 1750]);

    // the new rate, open-ended from the changeover
    TaxRate::factory()->for($taxClass)->create([
        'country_code' => 'GB',
        'rate_bp' => 2000,
        'validity' => sprintf('[%s,)', $changeover->toIso8601String()),
    ]);

    $sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    $resolver = new TaxRateResolver;

    $beforeOrder = $resolver->resolve($sku->id, companyId: null, countryCode: 'GB', at: $changeover->subDay());
    $afterOrder = $resolver->resolve($sku->id, companyId: null, countryCode: 'GB', at: $changeover->addDay());

    expect($beforeOrder)->toBe(1750)
        ->and($afterOrder)->toBe(2000);

    // re-resolving the EARLIER order after the change still reproduces
    // its original rate — a historical invoice reprints identically
    // (CLAUDE.md invariant 4), it is never retroactively repriced.
    expect($resolver->resolve($sku->id, companyId: null, countryCode: 'GB', at: $changeover->subDay()))->toBe(1750);
});

it('zeroes the rate for a tax-exempt company regardless of the SKU\'s own class (03 §10)', function () {
    $taxClass = TaxClass::factory()->standard()->create();
    TaxRate::factory()->for($taxClass)->standard()->create(['country_code' => 'GB']);
    $sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    $company = Company::factory()->create(['tax_exempt' => true]);

    $rateBp = (new TaxRateResolver)->resolve($sku->id, companyId: $company->id, countryCode: 'GB', at: CarbonImmutable::now());

    expect($rateBp)->toBe(0);
});

it('does not zero the rate for a non-exempt company', function () {
    $taxClass = TaxClass::factory()->standard()->create();
    TaxRate::factory()->for($taxClass)->standard()->create(['country_code' => 'GB']);
    $sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    $company = Company::factory()->create(['tax_exempt' => false]);

    $rateBp = (new TaxRateResolver)->resolve($sku->id, companyId: $company->id, countryCode: 'GB', at: CarbonImmutable::now());

    expect($rateBp)->toBe(2000);
});

it('throws NoTaxRateException rather than guessing when no rate matches', function () {
    $taxClass = TaxClass::factory()->create(); // no tax_rates row at all
    $sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);

    expect(fn () => (new TaxRateResolver)->resolve($sku->id, companyId: null, countryCode: 'GB', at: CarbonImmutable::now()))
        ->toThrow(NoTaxRateException::class);
});
