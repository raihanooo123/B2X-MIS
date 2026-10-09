<?php

namespace App\Filament\Support;

use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "All" plus one list tab per status, each with its row count — one grouped
 * count query for the whole set, not one per tab.
 */
final class StatusTabs
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $statuses  value => label
     * @return array<string, Tab>
     */
    public static function for(string $model, array $statuses): array
    {
        $counts = $model::query()
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $tabs = ['all' => Tab::make('All')->badge((int) $counts->sum())];

        foreach ($statuses as $status => $label) {
            $tabs[$status] = Tab::make($label)
                ->badge((int) ($counts[$status] ?? 0))
                ->badgeColor(CatalogueStatus::color($status))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
        }

        return $tabs;
    }
}
