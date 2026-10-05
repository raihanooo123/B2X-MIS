<?php

namespace App\Observers;

use App\Jobs\RefreshPriceProjection;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Sku;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;

/**
 * 02 §29.4 — the event-driven refresh of the storefront price sort key.
 * Each write that could change a product's "from" price queues a refresh
 * of exactly the products it touches, after commit:
 *
 *   - a product or SKU saved or deleted (status, deletion, tax class, the
 *     product it belongs to);
 *   - an item of a `base` price list saved or deleted;
 *   - a `base` price list saved or deleted (activation, archive, validity)
 *     — every product;
 *   - a GB tax rate saved or deleted — every product in that tax class.
 *
 * Writes that bypass Eloquent (imports, raw SQL) are caught by the nightly
 * rebuild; a time boundary with no write by `stale_after`.
 */
final class PriceProjectionObserver
{
    public function saved(Model $model): void
    {
        $this->queue($model);
    }

    public function deleted(Model $model): void
    {
        $this->queue($model);
    }

    private function queue(Model $model): void
    {
        match (true) {
            $model instanceof Product => RefreshPriceProjection::dispatch([(int) $model->getKey()]),
            $model instanceof Sku => RefreshPriceProjection::dispatch(array_values(array_unique(array_filter(
                [(int) $model->getAttribute('product_id'), (int) $model->getOriginal('product_id')],
            )))),
            $model instanceof PriceListItem => $this->priceListItem($model),
            $model instanceof PriceList => $model->getAttribute('scope') === 'base' || $model->getOriginal('scope') === 'base'
                ? RefreshPriceProjection::dispatch(null) : null,
            $model instanceof TaxRate => $model->getAttribute('country_code') === 'GB'
                ? RefreshPriceProjection::dispatch([], [(int) $model->getAttribute('tax_class_id')]) : null,
            default => null,
        };
    }

    private function priceListItem(PriceListItem $item): void
    {
        $scope = PriceList::query()->where('id', $item->getAttribute('price_list_id'))->value('scope');
        if ($scope !== 'base') {
            return;
        }
        $productId = Sku::query()->where('id', $item->getAttribute('sku_id'))->value('product_id');
        if ($productId !== null) {
            RefreshPriceProjection::dispatch([(int) $productId]);
        }
    }
}
