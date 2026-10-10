<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TradeApplicationResource;
use App\Models\B2bApplication;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** The oldest open trade applications first, as the review queue (05.2 §5). */
class TradeApplicationQueue extends TableWidget
{
    /** Rendered with the dashboard, not fetched after it loads. */
    protected static bool $isLazy = false;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 2];

    protected static ?string $heading = 'Trade applications waiting';

    public static function canView(): bool
    {
        return TradeApplicationResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(B2bApplication::query()->whereIn('status', B2bApplication::OPEN_STATUSES)->orderBy('submitted_at'))
            ->columns([
                TextColumn::make('company_name')->label('Company')->weight('medium')
                    ->description(fn (B2bApplication $record): string => $record->contact_name),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state): string => $state === 'info_requested' ? 'warning' : 'info'),
                TextColumn::make('submitted_at')->label('Submitted')->since()->alignEnd(),
            ])
            ->recordUrl(fn (B2bApplication $record): string => TradeApplicationResource::getUrl('view', ['record' => $record]))
            ->paginated([5])
            ->defaultPaginationPageOption(5)
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No applications waiting')
            ->emptyStateDescription('New trade applications appear here as they are submitted.');
    }
}
