<?php

namespace App\Filament\Resources;

use App\Domain\Collection\CollectionSlots;
use App\Filament\Resources\CollectionSlotResource\Pages;
use App\Models\CollectionSlot;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
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
    protected static ?string $model = CollectionSlot::class;

    protected static ?string $navigationGroup = 'Collections';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Slots';

    protected static ?string $modelLabel = 'collection slot';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('location:id,name'))
            ->columns([
                TextColumn::make('slot_date')->label('Date')->date('D j M Y')->sortable(),
                TextColumn::make('time')->label('Time (UK)')
                    ->state(fn (CollectionSlot $record) => substr($record->start_time, 0, 5).'–'.substr($record->end_time, 0, 5)),
                TextColumn::make('location.name')->label('Location'),
                TextColumn::make('booked_count')->label('Booked')
                    ->state(fn (CollectionSlot $record) => "{$record->booked_count} / {$record->capacity}"),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'open' => 'success',
                    'full' => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('note')->limit(40)->placeholder('—'),
            ])
            ->filters([
                Filter::make('upcoming')->label('Today and later')->default()
                    ->query(fn (Builder $query) => $query->where('slot_date', '>=', now(CollectionSlots::ZONE)->toDateString())),
                SelectFilter::make('status')->options(['open' => 'Open', 'full' => 'Full', 'closed' => 'Closed']),
                SelectFilter::make('location_id')->label('Location')->relationship('location', 'name'),
            ])
            ->actions([
                Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (CollectionSlot $record) => $record->status !== 'closed' && Gate::allows('update', $record))
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
                    ->icon('heroicon-o-lock-open')
                    ->visible(fn (CollectionSlot $record) => $record->status === 'closed' && Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->action(function (CollectionSlot $record): void {
                        (new CollectionSlots)->reopen($record->id);
                        Notification::make()->title('Slot reopened')->success()->send();
                    }),
            ])
            ->defaultSort('slot_date')
            ->paginated([25, 50, 100]);
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
        ];
    }
}
