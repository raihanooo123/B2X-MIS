<?php

namespace App\Filament\Support;

use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "All" plus one list tab per status (or group of statuses), each with its
 * row count — one grouped count query for the whole set, not one per tab.
 */
final class StatusTabs
{
    /**
     * One tab per status, coloured as the catalogue badges are.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $statuses  value => label
     * @return array<string, Tab>
     */
    public static function for(string $model, array $statuses): array
    {
        $groups = [];
        foreach ($statuses as $status => $label) {
            $groups[$status] = [$label, [$status], CatalogueStatus::color($status)];
        }

        return self::groups($model, $groups);
    }

    /**
     * One tab per group of statuses, e.g. "Open" covering several.
     *
     * `$source` is a model class, or the resource's own scoped query when
     * the list shows only some of the model's rows (customer users, not staff).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>|Builder<TModel>  $source
     * @param  array<string, array{0: string, 1: list<string>, 2: string}>  $groups  key => [label, statuses, badge colour]
     * @return array<string, Tab>
     */
    public static function groups(string|Builder $source, array $groups): array
    {
        $query = is_string($source) ? $source::query() : $source->clone();

        $counts = $query
            ->toBase()
            ->reorder()
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $tabs = ['all' => Tab::make('All')->badge((int) $counts->sum())];

        foreach ($groups as $key => [$label, $statuses, $colour]) {
            $count = 0;
            foreach ($statuses as $status) {
                $count += (int) ($counts[$status] ?? 0);
            }

            $tabs[$key] = Tab::make($label)
                ->badge($count)
                ->badgeColor($colour)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', $statuses));
        }

        return $tabs;
    }
}
