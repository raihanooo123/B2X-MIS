<?php

namespace App\Filament\Support;

use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Livewire\Livewire;

/**
 * Draws a list page's status tabs in the table toolbar, beside the search,
 * instead of as a separate bar above the table. Only when the table shows
 * a toolbar (search, filters, column toggles or reordering); otherwise the
 * page keeps Filament's own tabs. The theme hides those when these render.
 */
final class ToolbarTabs
{
    public static function render(): string
    {
        $page = Livewire::current();

        if (! $page instanceof ListRecords || ! self::hasToolbar($page->getTable())) {
            return '';
        }

        $tabs = $page->getCachedTabs();

        if ($tabs === []) {
            return '';
        }

        return view('filament.tables.toolbar-tabs', ['page' => $page, 'tabs' => $tabs])->render();
    }

    /** Filament's own condition for showing the table toolbar (tables::index). */
    private static function hasToolbar(Table $table): bool
    {
        return $table->isSearchable()
            || $table->hasToggleableColumns()
            || $table->isReorderable()
            || ($table->isFilterable() && in_array($table->getFiltersLayout(), [FiltersLayout::Dropdown, FiltersLayout::Modal], true));
    }
}
