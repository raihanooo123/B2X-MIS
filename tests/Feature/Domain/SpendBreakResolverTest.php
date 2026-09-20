<?php

use App\Domain\Pricing\SpendBreakResolver;
use App\Models\Company;
use App\Models\OrderSpendBreak;
use App\Models\PriceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not select a break below its threshold', function () {
    OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000]);

    $result = (new SpendBreakResolver)->resolve(99999, null, null, 'GBP');

    expect($result)->toBeNull();
});

it('selects a global break once its threshold is met', function () {
    $break = OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000]);

    $result = (new SpendBreakResolver)->resolve(100000, null, null, 'GBP');

    expect($result?->id)->toBe($break->id);
});

it('prefers a company break over a tier and global break at the same threshold', function () {
    $tier = PriceTier::factory()->create();
    $company = Company::factory()->create(['price_tier_id' => $tier->id]);
    OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000]);
    OrderSpendBreak::factory()->forTier($tier)->create(['min_subtotal_minor' => 100000]);
    $companyBreak = OrderSpendBreak::factory()->forCompany($company)->create(['min_subtotal_minor' => 100000]);

    $result = (new SpendBreakResolver)->resolve(100000, $tier->id, $company->id, 'GBP');

    expect($result?->id)->toBe($companyBreak->id);
});

it('prefers a tier break over a global break when no company break applies', function () {
    $tier = PriceTier::factory()->create();
    OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000]);
    $tierBreak = OrderSpendBreak::factory()->forTier($tier)->create(['min_subtotal_minor' => 100000]);

    $result = (new SpendBreakResolver)->resolve(100000, $tier->id, null, 'GBP');

    expect($result?->id)->toBe($tierBreak->id);
});

it('never resolves a company-scoped break for a different company', function () {
    $otherCompany = Company::factory()->create();
    OrderSpendBreak::factory()->forCompany($otherCompany)->create(['min_subtotal_minor' => 100000]);
    $globalBreak = OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000]);

    $result = (new SpendBreakResolver)->resolve(100000, null, null, 'GBP');

    expect($result?->id)->toBe($globalBreak->id);
});

it('does not select a draft break', function () {
    OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000, 'status' => 'draft']);

    $result = (new SpendBreakResolver)->resolve(100000, null, null, 'GBP');

    expect($result)->toBeNull();
});
