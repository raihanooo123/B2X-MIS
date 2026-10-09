<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\StorefrontSettingsPage;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\SkuResource;
use App\Models\CollectionBooking;
use App\Models\GoodsReceipt;
use App\Models\Shipment;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;

/** Shortcuts to the day's common jobs, each behind the policy of the screen it opens. */
class QuickLinks extends Widget
{
    protected static ?int $sort = 30;

    protected static string $view = 'filament.widgets.quick-links';

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return self::links() !== [];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['links' => self::links()];
    }

    /** @return list<array{label: string, icon: string, url: string}> */
    private static function links(): array
    {
        $links = [
            [ProductResource::canCreate(), 'New product', 'heroicon-o-plus-circle', fn (): string => ProductResource::getUrl('create')],
            [SkuResource::canViewAny(), 'Find a SKU', 'heroicon-o-magnifying-glass', fn (): string => SkuResource::getUrl('index')],
            [PurchaseOrderResource::canCreate(), 'Raise a purchase order', 'heroicon-o-document-plus', fn (): string => PurchaseOrderResource::getUrl('create')],
            [Gate::allows('viewAny', GoodsReceipt::class), 'Goods in', 'heroicon-o-inbox-arrow-down', fn (): string => route('warehouse.goods-in')],
            [Gate::allows('viewAny', Shipment::class), 'Picking', 'heroicon-o-clipboard-document-list', fn (): string => route('warehouse.pick-list')],
            [Gate::allows('viewAny', Shipment::class), 'Dispatch', 'heroicon-o-truck', fn (): string => route('warehouse.dispatch')],
            [Gate::allows('viewAny', CollectionBooking::class), 'Collections counter', 'heroicon-o-building-storefront', fn (): string => route('warehouse.collections')],
            [StorefrontSettingsPage::canAccess(), 'Storefront settings', 'heroicon-o-paint-brush', fn (): string => StorefrontSettingsPage::getUrl()],
        ];

        $visible = [];
        foreach ($links as [$allowed, $label, $icon, $url]) {
            if ($allowed) {
                $visible[] = ['label' => $label, 'icon' => $icon, 'url' => $url()];
            }
        }

        return $visible;
    }
}
