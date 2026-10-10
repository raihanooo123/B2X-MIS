<x-filament-widgets::widget>
    <x-filament::section heading="Quick links">
        <nav aria-label="Quick links" class="grid gap-2">
            @foreach ($links as $link)
                <a href="{{ $link['url'] }}" class="b2x-quick-link">
                    <x-filament::icon :icon="$link['icon']" class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
                    <span>{{ $link['label'] }}</span>
                </a>
            @endforeach
        </nav>
    </x-filament::section>
</x-filament-widgets::widget>
