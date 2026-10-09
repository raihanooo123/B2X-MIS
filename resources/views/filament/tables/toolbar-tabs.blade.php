{{-- A list page's status tabs, drawn in the table toolbar beside the search (ToolbarTabs). --}}
@php
    $activeTab = strval($page->activeTab);
@endphp

<x-filament::tabs class="b2x-toolbar-tabs" label="Filter by status">
    @foreach ($tabs as $tabKey => $tab)
        @php
            $tabKey = strval($tabKey);
        @endphp

        <x-filament::tabs.item
            :active="$activeTab === $tabKey"
            :badge="$tab->getBadge()"
            :badge-color="$tab->getBadgeColor()"
            :badge-icon="$tab->getBadgeIcon()"
            :badge-icon-position="$tab->getBadgeIconPosition()"
            :icon="$tab->getIcon()"
            :icon-position="$tab->getIconPosition()"
            :wire:click="'$set(\'activeTab\', ' . (filled($tabKey) ? ('\'' . $tabKey . '\'') : 'null') . ')'"
            :attributes="$tab->getExtraAttributeBag()"
        >
            {{ $tab->getLabel() ?? $page->generateTabLabel($tabKey) }}
        </x-filament::tabs.item>
    @endforeach
</x-filament::tabs>
