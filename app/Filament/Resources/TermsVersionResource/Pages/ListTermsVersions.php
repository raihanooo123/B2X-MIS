<?php

namespace App\Filament\Resources\TermsVersionResource\Pages;

use App\Domain\Accounts\TermsKind;
use App\Filament\Resources\TermsVersionResource;
use App\Models\TermsVersion;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTermsVersions extends ListRecords
{
    protected static string $resource = TermsVersionResource::class;

    public function getSubheading(): string
    {
        return 'Every version of your terms that customers have agreed to. A published version is never changed: publish a new one instead.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Publish a new version')->icon('heroicon-m-plus')];
    }

    /** One tab per set of terms. */
    public function getTabs(): array
    {
        $counts = TermsVersion::query()->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');

        $tabs = ['all' => Tab::make('All')->badge((int) $counts->sum())];
        foreach (TermsKind::cases() as $kind) {
            $tabs[$kind->value] = Tab::make($kind->label())
                ->badge((int) ($counts[$kind->value] ?? 0))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', $kind->value));
        }

        return $tabs;
    }
}
