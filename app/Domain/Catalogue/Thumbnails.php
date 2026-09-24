<?php

namespace App\Domain\Catalogue;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Thumbnail URLs for a set of SKUs in one query — the SKU's own first
 * image, else its product's (05.1 §4.2) — shared by the order pad
 * (OrderPadCatalogue) and the cart (CartResource).
 *
 * Images come from `media` (`media_sku_idx` / `media_product_idx`). A disk
 * that cannot build a URL (e.g. s3 unconfigured locally) yields null →
 * placeholder, as ProductResource's thumbnail column already does.
 */
final class Thumbnails
{
    /**
     * @param  list<int>  $skuIds
     * @param  list<int>  $productIds
     * @return array{sku: array<int, string>, product: array<int, string>}
     */
    public function lookup(array $skuIds, array $productIds): array
    {
        $result = ['sku' => [], 'product' => []];

        if ($skuIds === []) {
            return $result;
        }

        $media = Media::query()
            ->where('media_type', 'image')
            ->where(fn ($q) => $q->whereIn('sku_id', $skuIds)->orWhere(fn ($q) => $q->whereNull('sku_id')->whereIn('product_id', $productIds)))
            ->orderBy('position')
            ->orderBy('id')
            ->get(['sku_id', 'product_id', 'disk', 'path']);

        foreach ($media as $item) {
            $url = $this->url((string) $item->getAttribute('disk'), (string) $item->getAttribute('path'));
            if ($url === null) {
                continue;
            }

            $skuId = $item->getAttribute('sku_id');
            if ($skuId !== null) {
                $result['sku'][(int) $skuId] ??= $url;
            } else {
                $result['product'][(int) $item->getAttribute('product_id')] ??= $url;
            }
        }

        return $result;
    }

    /**
     * @param  array{sku: array<int, string>, product: array<int, string>}  $lookup
     */
    public static function pick(array $lookup, int $skuId, int $productId): ?string
    {
        return $lookup['sku'][$skuId] ?? $lookup['product'][$productId] ?? null;
    }

    private function url(string $disk, string $path): ?string
    {
        try {
            return Storage::disk($disk)->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
