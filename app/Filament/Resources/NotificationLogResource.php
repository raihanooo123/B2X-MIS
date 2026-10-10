<?php

namespace App\Filament\Resources;

use App\Domain\Notifications\NotificationKey;
use App\Filament\Resources\NotificationLogResource\Pages;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\NotificationLog;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 02 §22.2 / 05.12 §10.3 — every message and its outcome, read-only.
 * "Undelivered invoices" is the list accounts staff work: an invoice email
 * that bounced or failed must not age silently into an overdue dispute.
 *
 * The provider's message ID and the de-duplication key are internal and
 * are never shown.
 */
class NotificationLogResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = NotificationLog::class;

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $modelLabel = 'notification';

    /** Messages about money documents (05.12 §10.3). */
    public const MONEY_KEYS = ['invoice.issued', 'invoice.due_soon', 'invoice.overdue', 'payment.received'];

    public const PROBLEM_STATUSES = ['bounced', 'failed', 'complained'];

    public static function canCreate(): bool
    {
        return false;
    }

    /** "invoice.due_soon" → "Invoice: due soon". */
    public static function keyLabel(string $key): string
    {
        [$area, $event] = array_pad(explode('.', $key, 2), 2, '');

        return ucfirst(str_replace('_', ' ', $area)).($event === '' ? '' : ': '.str_replace('_', ' ', $event));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'delivered' => 'success',
            'sent', 'queued' => 'info',
            'bounced', 'failed' => 'danger',
            'complained' => 'warning',
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
                        Section::make('Message')
                            ->icon('heroicon-o-envelope')
                            ->schema([
                                TextEntry::make('notification_key')->label('Notification')
                                    ->formatStateUsing(fn (string $state): string => self::keyLabel($state))
                                    ->size(TextEntry\TextEntrySize::Large)->weight('semibold')->columnSpanFull(),
                                TextEntry::make('recipient')->copyable(),
                                TextEntry::make('channel')->formatStateUsing(fn (string $state): string => ucfirst($state)),
                                TextEntry::make('subject')->label('About')
                                    ->state(fn (NotificationLog $record) => $record->subject_type === null ? null : "{$record->subject_type} #{$record->subject_id}")
                                    ->placeholder('—'),
                                TextEntry::make('category')->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                            ])
                            ->columns(2),

                        Section::make('What happened')
                            ->icon('heroicon-o-exclamation-triangle')
                            ->visible(fn (NotificationLog $record): bool => $record->last_error !== null)
                            ->schema([
                                TextEntry::make('last_error')->hiddenLabel(),
                            ]),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                TextEntry::make('status')->badge()
                                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                                    ->color(fn (string $state): string => self::statusColor($state)),
                                TextEntry::make('attempts')->label('Attempts'),
                            ]),
                        Section::make('Timeline')
                            ->schema([
                                TextEntry::make('queued_at')->label('Queued')->dateTime(),
                                TextEntry::make('sent_at')->label('Sent')->dateTime()->placeholder('—'),
                                TextEntry::make('delivered_at')->label('Delivered')->dateTime()->placeholder('—'),
                                TextEntry::make('failed_at')->label('Failed')->dateTime()->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('notification_key')->label('Notification')->weight('medium')
                    ->formatStateUsing(fn (string $state): string => self::keyLabel($state))
                    ->description(fn (NotificationLog $record): string => $record->recipient)
                    ->searchable(['notification_key', 'recipient']),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('queued_at')->label('Queued')->dateTime()->sortable(),
                TextColumn::make('delivered_at')->label('Delivered')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('last_error')->label('Detail')->limit(60)->placeholder('—')->toggleable(),
                TextColumn::make('subject')
                    ->state(fn (NotificationLog $record) => $record->subject_type === null ? null : "{$record->subject_type} #{$record->subject_id}")
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('notification_key')->label('Notification')
                    ->options(fn (): array => collect(NotificationKey::cases())->mapWithKeys(fn (NotificationKey $k) => [$k->value => self::keyLabel($k->value)])->all())
                    ->searchable(),
            ])
            ->actions([ViewAction::make()->iconButton()->tooltip('View')])
            ->striped()
            ->defaultSort('queued_at', 'desc')
            ->emptyStateIcon('heroicon-o-envelope')
            ->emptyStateHeading('No messages here')
            ->emptyStateDescription('Every email the system sends is logged here with its delivery result.');
    }

    /**
     * Invoice and payment emails that bounced or failed.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function undeliveredMoney(Builder $query): Builder
    {
        return $query->whereIn('notification_key', self::MONEY_KEYS)->whereIn('status', ['bounced', 'failed']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationLogs::route('/'),
            'view' => Pages\ViewNotificationLog::route('/{record}'),
        ];
    }
}
