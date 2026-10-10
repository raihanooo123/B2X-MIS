<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StocktakeResource\Pages;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Stocktake;
use Filament\Infolists\Components\Group;
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
    use SentenceCaseLabels;

    protected static ?string $model = Stocktake::class;

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

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'posted' => 'success',
            'review' => 'warning',
            'cancelled' => 'gray',
            default => 'info',
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Count')
                            ->icon('heroicon-o-clipboard-document-check')
                            ->schema([
                                TextEntry::make('location.name')->label('Location')
                                    ->size(TextEntry\TextEntrySize::Large)->weight('semibold'),
                                TextEntry::make('is_blind')->label('Blind count')
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes — counters did not see the expected quantity' : 'No'),
                                TextEntry::make('lines_summary')->label('Lines counted')
                                    ->state(fn (Stocktake $record): string => self::linesSummary($record)),
                                TextEntry::make('public_id')->label('Reference')->fontFamily('mono')->copyable()->color('gray'),
                            ])
                            ->columns(2),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                TextEntry::make('status')->badge()
                                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                                    ->color(fn (string $state): string => self::statusColor($state)),
                            ]),
                        Section::make('Who and when')
                            ->schema([
                                TextEntry::make('startedBy.email')->label('Started by')->placeholder('—'),
                                TextEntry::make('started_at')->label('Started')->dateTime(),
                                TextEntry::make('postedBy.email')->label('Posted by')->placeholder('—'),
                                TextEntry::make('posted_at')->label('Posted')->dateTime()->placeholder('Not posted'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['location:id,code,name', 'startedBy:id,email'])
                ->withCount(['lines', 'lines as variance_lines_count' => fn (Builder $q) => $q->where('variance_base_qty', '<>', 0)]))
            ->columns([
                TextColumn::make('location.code')->label('Location')->weight('medium')->sortable()
                    ->description(fn (Stocktake $record): ?string => $record->location?->name),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('lines_count')->label('Lines')->alignEnd(),
                TextColumn::make('variance_lines_count')->label('With variance')->alignEnd()
                    ->color(fn (int $state): ?string => $state > 0 ? 'warning' : null)
                    ->weight(fn (int $state): string => $state > 0 ? 'semibold' : 'normal'),
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable()
                    ->description(fn (Stocktake $record): ?string => $record->startedBy?->email),
                TextColumn::make('posted_at')->label('Posted')->dateTime()->placeholder('—')->sortable()
                    ->toggleable(),
                IconColumn::make('is_blind')->label('Blind')->boolean()->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ViewAction::make()->iconButton()->tooltip('View'),
            ])
            ->filters([
                SelectFilter::make('location_id')->label('Location')->relationship('location', 'name'),
            ])
            ->striped()
            ->defaultSort('started_at', 'desc')
            ->emptyStateIcon('heroicon-o-clipboard-document-check')
            ->emptyStateHeading('No stocktakes yet')
            ->emptyStateDescription('Counts are started and posted on the warehouse Stocktake screen.');
    }

    public static function getRelations(): array
    {
        return [
            StocktakeResource\RelationManagers\LinesRelationManager::class,
        ];
    }

    /** "12 lines, 3 with a variance". */
    private static function linesSummary(Stocktake $record): string
    {
        $lines = $record->lines()->count();
        $variance = $record->lines()->where('variance_base_qty', '<>', 0)->count();

        return "{$lines} ".($lines === 1 ? 'line' : 'lines').", {$variance} with a variance";
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStocktakes::route('/'),
            'view' => Pages\ViewStocktake::route('/{record}'),
        ];
    }
}
