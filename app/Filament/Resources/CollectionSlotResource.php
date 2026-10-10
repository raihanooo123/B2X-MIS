<?php

namespace App\Filament\Resources;

use App\Domain\Collection\CollectionSlots;
use App\Filament\Resources\CollectionSlotResource\Pages;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\CollectionSlot;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * 05.6 §7A.1 — collection slots, as generated from each location's weekly
 * pattern. Never created or deleted here: staff close a slot, or a whole
 * day (Close a day, on the list), and reopen it. A closed slot keeps its
 * bookings until staff move them; the booked count says how many.
 */
class CollectionSlotResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = CollectionSlot::class;

    protected static ?string $navigationGroup = 'Collections';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Slots';

    protected static ?string $modelLabel = 'collection slot';

    public static function canCreate(): bool
    {
        return false;
    }

    public const STATUSES = [
        'open' => 'Open',
        'full' => 'Full',
        'closed' => 'Closed',
    ];

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'open' => 'success',
            'full' => 'warning',
            default => 'gray',
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
                        Section::make('Slot')
                            ->icon('heroicon-o-calendar-days')
                            ->schema([
                                TextEntry::make('slot_date')->label('Date')->date('l j F Y')
                                    ->size(TextEntry\TextEntrySize::Large)->weight('semibold'),
                                TextEntry::make('time')->label('Time (UK)')
                                    ->state(fn (CollectionSlot $record): string => self::time($record)),
                                TextEntry::make('location.name')->label('Location'),
                                TextEntry::make('note')->label('Note for staff')->placeholder('No note.'),
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
                                TextEntry::make('booked')->label('Booked')
                                    ->state(fn (CollectionSlot $record): string => "{$record->booked_count} of {$record->capacity}"),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('location:id,name'))
            ->columns([
                TextColumn::make('slot_date')->label('Date')->date('D j M Y')->sortable()->weight('medium')
                    ->description(fn (CollectionSlot $record): string => self::time($record)),
                TextColumn::make('location.name')->label('Location'),
                TextColumn::make('booked_count')->label('Booked')->alignEnd()
                    ->state(fn (CollectionSlot $record) => "{$record->booked_count} / {$record->capacity}"),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('note')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Filter::make('upcoming')->label('Today and later')->default()
                    ->query(fn (Builder $query) => $query->where('slot_date', '>=', now(CollectionSlots::ZONE)->toDateString())),
                SelectFilter::make('location_id')->label('Location')->relationship('location', 'name'),
            ])
            ->actions([
                ViewAction::make()->iconButton()->tooltip('View bookings'),
                Action::make('close')
                    ->label('Close')
                    ->iconButton()
                    ->tooltip('Close this slot')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (CollectionSlot $record) => $record->status !== 'closed' && Gate::allows('update', $record))
                    ->modalHeading('Close slot')
                    ->form([Textarea::make('note')->label('Why (shown to staff)')->maxLength(500)])
                    ->modalDescription(fn (CollectionSlot $record) => $record->booked_count > 0
                        ? "{$record->booked_count} booking(s) on this slot stay on it until you move them to another slot."
                        : 'No one can book this slot while it is closed.')
                    ->action(function (CollectionSlot $record, array $data): void {
                        (new CollectionSlots)->close([$record->id], self::note($data));
                        Notification::make()->title('Slot closed')->success()->send();
                    }),
                Action::make('reopen')
                    ->label('Reopen')
                    ->iconButton()
                    ->tooltip('Reopen this slot')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->visible(fn (CollectionSlot $record) => $record->status === 'closed' && Gate::allows('update', $record))
                    ->modalHeading('Reopen slot')
                    ->requiresConfirmation()
                    ->action(function (CollectionSlot $record): void {
                        (new CollectionSlots)->reopen($record->id);
                        Notification::make()->title('Slot reopened')->success()->send();
                    }),
            ])
            ->striped()
            ->defaultSort('slot_date')
            ->paginated([25, 50, 100])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading('No slots here')
            ->emptyStateDescription('Slots are made from each location\'s weekly pattern. Clear the "Today and later" filter to see past slots.');
    }

    public static function time(CollectionSlot $record): string
    {
        return substr($record->start_time, 0, 5).'–'.substr($record->end_time, 0, 5);
    }

    public static function getRelations(): array
    {
        return [
            CollectionSlotResource\RelationManagers\BookingsRelationManager::class,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function note(array $data): ?string
    {
        $note = trim((string) ($data['note'] ?? ''));

        return $note === '' ? null : $note;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCollectionSlots::route('/'),
            'view' => Pages\ViewCollectionSlot::route('/{record}'),
        ];
    }
}
