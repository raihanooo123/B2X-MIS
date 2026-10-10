<?php

namespace App\Filament\Resources\ReorderSuggestionResource\Pages;

use App\Filament\Resources\ReorderSuggestionResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListReorderSuggestions extends ListRecords
{
    protected static string $resource = ReorderSuggestionResource::class;

    public function getSubheading(): string
    {
        return 'SKUs worth buying again, based on stock, stock on order and recent sales. A guide only: check before raising a purchase order.';
    }

    /** Counted in one pass over the same rows the list shows. */
    public function getTabs(): array
    {
        $counts = ReorderSuggestionResource::getEloquentQuery()
            ->toBase()
            ->selectRaw('COUNT(*) AS total, COUNT(*) FILTER (WHERE below_reorder_point) AS below, COUNT(*) FILTER (WHERE cover_short) AS short')
            ->first();

        return [
            'all' => Tab::make('All')->badge((int) ($counts->total ?? 0)),
            'below' => Tab::make('Below reorder point')->badge((int) ($counts->below ?? 0))->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('below_reorder_point', true)),
            'short' => Tab::make('Will run short')->badge((int) ($counts->short ?? 0))->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('cover_short', true)),
        ];
    }
}
