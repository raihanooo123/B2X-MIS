<?php

namespace App\Filament\Resources;

use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\NotificationStatus;
use App\Filament\Resources\NotificationLogResource\Pages;
use App\Models\NotificationLog;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Doc 02 §22.2 / 05.12 §10.3 — every message and its outcome, read-only.
 * "Undelivered invoices" is the list accounts staff work: an invoice email
 * that bounced or failed must not age silently into an overdue dispute.
 */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $modelLabel = 'notification';

    /** Messages about money documents (05.12 §10.3). */
    private const MONEY_KEYS = ['invoice.issued', 'invoice.due_soon', 'invoice.overdue', 'payment.received'];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $statuses = collect(NotificationStatus::cases())->mapWithKeys(fn (NotificationStatus $s) => [$s->value => ucfirst($s->value)])->all();
        $keys = collect(NotificationKey::cases())->mapWithKeys(fn (NotificationKey $k) => [$k->value => $k->value])->all();

        return $table
            ->columns([
                TextColumn::make('queued_at')->label('Queued')->dateTime()->sortable(),
                TextColumn::make('notification_key')->label('Notification')->searchable(),
                TextColumn::make('recipient')->searchable(),
                TextColumn::make('subject')
                    ->state(fn (NotificationLog $record) => $record->subject_type === null ? null : "{$record->subject_type} #{$record->subject_id}")
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'delivered' => 'success',
                        'sent', 'queued' => 'info',
                        'bounced', 'failed' => 'danger',
                        'complained' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('attempts')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delivered_at')->label('Delivered')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('last_error')->label('Detail')->limit(60)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Filter::make('undelivered_money')
                    ->label('Undelivered invoices')
                    ->query(fn (Builder $query) => $query
                        ->whereIn('notification_key', self::MONEY_KEYS)
                        ->whereIn('status', ['bounced', 'failed'])),
                SelectFilter::make('status')->options($statuses),
                SelectFilter::make('notification_key')->label('Notification')->options($keys),
            ])
            ->defaultSort('queued_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationLogs::route('/'),
        ];
    }
}
