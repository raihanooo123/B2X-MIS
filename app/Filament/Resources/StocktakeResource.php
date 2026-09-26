<?php

namespace App\Filament\Resources;

use App\Domain\Warehouse\StocktakeReason;
use App\Filament\Resources\StocktakeResource\Pages;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Doc 02 §14.7, §24 — stocktakes, read-only. Counted and posted on the
 * warehouse screen (StocktakeService); here purchasing, accounts and
 * managers see what was counted, what was expected when it was counted,
 * the variance, its reason, and the serials scanned. Nothing is created
 * or edited here: a posted stocktake is the record behind its movements.
 * StocktakePolicy authorises, as everywhere else.
 */
class StocktakeResource extends Resource
{
    protected static ?string $model = Stocktake::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Warehouse';

    protected static ?string $navigationLabel = 'Stocktake history';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'public_id';

    public const STATUSES = [
        'open' => 'Counting',
        'review' => 'In review',
        'posted' => 'Posted',
        'cancelled' => 'Cancelled',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Stocktake')
                ->schema([
                    TextEntry::make('location.code')->label('Location'),
                    TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state),
                    TextEntry::make('is_blind')->label('Blind')->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No'),
                    TextEntry::make('startedBy.email')->label('Started by')->placeholder('—'),
                    TextEntry::make('started_at')->label('Started')->dateTime(),
                    TextEntry::make('posted_at')->label('Posted')->dateTime()->placeholder('—'),
                    TextEntry::make('postedBy.email')->label('Posted by')->placeholder('—'),
                ])
                ->columns(4),

            Section::make('Lines')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('sku.sku_code')->label('SKU'),
                            TextEntry::make('batch.batch_code')->label('Batch')->placeholder('—'),
                            TextEntry::make('counted_base_qty')->label('Counted'),
                            TextEntry::make('counted_at')->label('Counted at')->dateTime(),
                            TextEntry::make('expected_base_qty')->label('Expected at count')->placeholder('Not posted'),
                            TextEntry::make('variance_base_qty')->label('Variance')->placeholder('—')
                                ->formatStateUsing(fn (?int $state) => $state === null ? '—' : sprintf('%+d', $state))
                                ->color(fn (?int $state) => $state === null || $state === 0 ? 'gray' : ($state > 0 ? 'success' : 'danger')),
                            TextEntry::make('reason_code')->label('Reason')->placeholder('—')
                                ->formatStateUsing(fn (?string $state) => $state === null ? '—' : (StocktakeReason::tryFrom($state)?->label() ?? $state)),
                            TextEntry::make('serials')->label('Serials scanned')->placeholder('—')
                                ->state(fn (StocktakeLine $record) => $record->serials->sortBy('serial_number')->pluck('serial_number')->implode(', ') ?: null),
                        ])
                        ->columns(8),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['location:id,code'])
                ->withCount(['lines', 'lines as variance_lines_count' => fn (Builder $q) => $q->where('variance_base_qty', '<>', 0)]))
            ->columns([
                TextColumn::make('location.code')->label('Location')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'posted' => 'success',
                        'review' => 'warning',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
                IconColumn::make('is_blind')->label('Blind')->boolean(),
                TextColumn::make('lines_count')->label('Lines')->alignEnd(),
                TextColumn::make('variance_lines_count')->label('With variance')->alignEnd(),
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('posted_at')->label('Posted')->dateTime()->placeholder('—')->sortable(),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
            ])
            ->defaultSort('started_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStocktakes::route('/'),
            'view' => Pages\ViewStocktake::route('/{record}'),
        ];
    }
}
