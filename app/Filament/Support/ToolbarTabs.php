<?php

namespace App\Filament\Support;

use Filament\Resources\Pages\ListRecords;
use Livewire\Livewire;

/**
 * Draws a list page's status tabs in the table toolbar, beside the search,
 * instead of as a separate bar above the table. Only when the table is
 * searchable, so the toolbar they sit in is always shown; otherwise the
 * page keeps Filament's own tabs. The theme hides those when these render.
 */
final class ToolbarTabs
{
    public static function render(): string
    {
        $page = Livewire::current();

        if (! $page instanceof ListRecords || ! $page->getTable()->isSearchable()) {
            return '';
        }

        $tabs = $page->getCachedTabs();

        if ($tabs === []) {
            return '';
        }

        return view('filament.tables.toolbar-tabs', ['page' => $page, 'tabs' => $tabs])->render();
    }
}
