<?php

namespace App\Filament\Resources\ReorderSettingResource\Pages;

use App\Filament\Resources\ReorderSettingResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListReorderSettings extends ListRecords
{
    protected static string $resource = ReorderSettingResource::class;

    public function getSubheading(): string
    {
        return 'Choose when each SKU should be suggested for reordering, per location. All figures are in base units.';
    }

    /** Counted in one pass over the same rows the list shows. */
    public function getTabs(): array
    {
        $counts = ReorderSettingResource::getEloquentQuery()
            ->toBase()
            ->selectRaw('COUNT(*) AS total, COUNT(*) FILTER (WHERE reorder_point_base_qty > 0) AS with_point')
            ->first();

        $total = (int) ($counts->total ?? 0);
        $set = (int) ($counts->with_point ?? 0);

        return [
            'all' => Tab::make('All')->badge($total),
            'set' => Tab::make('Reorder point set')->badge($set)->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('reorder_point_base_qty', '>', 0)),
            'not_set' => Tab::make('Not set')->badge($total - $set)->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('reorder_point_base_qty', 0)),
        ];
    }
}
