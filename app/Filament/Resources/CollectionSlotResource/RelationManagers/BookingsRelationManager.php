<?php

namespace App\Filament\Resources\CollectionSlotResource\RelationManagers;

use App\Models\CollectionBooking;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is booked into the slot. Read-only: bookings are served, moved and
 * marked as no-shows at the collections counter. Shown to staff who may
 * see bookings (CollectionBookingPolicy, the default relation check).
 */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Bookings';

    protected static ?string $icon = 'heroicon-o-user-group';

    private const STATUSES = [
        'booked' => 'Booked',
        'collected' => 'Collected',
        'no_show' => 'No-show',
        'cancelled' => 'Cancelled',
    ];

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('order:id,public_id,order_number'))
            ->columns([
                TextColumn::make('order.order_number')->label('Order')->fontFamily('mono')->weight('medium')
                    ->url(fn (CollectionBooking $record): ?string => $record->order === null ? null : url('/warehouse/collections?order='.$record->order->public_id)),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'collected' => 'success',
                        'no_show' => 'danger',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('collector_name')->label('Collected by')->placeholder('—'),
                TextColumn::make('collected_at')->label('Collected')->dateTime()->placeholder('—'),
                TextColumn::make('payment_due_by')->label('Pay by')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateHeading('No bookings on this slot');
    }
}
