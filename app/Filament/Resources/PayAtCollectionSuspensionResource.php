<?php

namespace App\Filament\Resources;

use App\Domain\Collection\CashRefused;
use App\Domain\Collection\PayAtCollectionSuspensions;
use App\Filament\Resources\PayAtCollectionSuspensionResource\Pages;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\PayAtCollectionSuspension;
use App\Models\User;
use Filament\Actions\Action as PageAction;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * 05.6 §7A.11 — customers for whom pay at collection is withdrawn, by the
 * no-show limit or by hand. `accounts` lift a suspension with a reason
 * (counting restarts from the lift) or suspend a customer by hand. History
 * is kept: a lift never deletes, and the next suspension is a new row.
 */
class PayAtCollectionSuspensionResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = PayAtCollectionSuspension::class;

    protected static ?string $navigationGroup = 'Collections';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Pay at collection suspensions';

    protected static ?string $modelLabel = 'suspension';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function customer(PayAtCollectionSuspension $record): ?string
    {
        return $record->company !== null
            ? "{$record->company->name} ({$record->company->account_code})"
            : $record->user?->email;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Suspension')
                            ->icon('heroicon-o-no-symbol')
                            ->schema([
                                TextEntry::make('customer')->label('Customer')
                                    ->state(fn (PayAtCollectionSuspension $record): ?string => self::customer($record))
                                    ->size(TextEntry\TextEntrySize::Large)->weight('semibold')->columnSpanFull(),
                                TextEntry::make('reason')->label('Why')->badge()
                                    ->formatStateUsing(fn (string $state): string => $state === 'no_shows' ? 'No-shows' : 'Manual'),
                                TextEntry::make('no_show_count')->label('No-shows counted')->placeholder('—'),
                                TextEntry::make('note')->label('Note')->placeholder('No note.')->columnSpanFull(),
                            ])
                            ->columns(2),
                        Section::make('Lifted')
                            ->icon('heroicon-o-check-circle')
                            ->visible(fn (PayAtCollectionSuspension $record): bool => $record->lifted_at !== null)
                            ->schema([
                                TextEntry::make('lifted_at')->label('Lifted')->dateTime(),
                                TextEntry::make('lifted_by')->label('Lifted by')
                                    ->state(fn (PayAtCollectionSuspension $record): ?string => self::staffEmail($record->lifted_by_user_id))->placeholder('—'),
                                TextEntry::make('lift_reason')->label('Reason')->placeholder('—')->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                TextEntry::make('state')->label('Status')->badge()
                                    ->state(fn (PayAtCollectionSuspension $record): string => $record->lifted_at === null ? 'Active' : 'Lifted')
                                    ->color(fn (string $state): string => $state === 'Active' ? 'danger' : 'success'),
                                TextEntry::make('suspended_at')->label('Suspended')->dateTime(),
                                TextEntry::make('suspended_by')->label('Suspended by')
                                    ->state(fn (PayAtCollectionSuspension $record): string => self::staffEmail($record->suspended_by_user_id) ?? 'Automatically, at the no-show limit'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user:id,email,first_name,last_name', 'company:id,name,account_code']))
            ->columns([
                TextColumn::make('customer')->weight('medium')
                    ->state(fn (PayAtCollectionSuspension $record) => self::customer($record)),
                TextColumn::make('reason')->label('Why')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'no_shows' ? 'No-shows' : 'Manual')
                    ->color(fn (string $state): string => $state === 'no_shows' ? 'warning' : 'gray'),
                TextColumn::make('no_show_count')->label('No-shows')->alignEnd()->placeholder('—'),
                TextColumn::make('suspended_at')->label('Suspended')->dateTime()->sortable(),
                TextColumn::make('lifted_at')->label('Lifted')->dateTime()->placeholder('Active')->toggleable(),
                TextColumn::make('note')->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lift_reason')->label('Lift reason')->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ViewAction::make()->iconButton()->tooltip('View'),
                self::liftAction(Action::make('lift'))->iconButton()->tooltip('Lift suspension'),
            ])
            ->striped()
            ->defaultSort('suspended_at', 'desc')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No suspensions here')
            ->emptyStateDescription('A customer is suspended automatically at the no-show limit, or by hand with "Suspend a customer".');
    }

    /**
     * The one lift path, for the list row and the detail page: same gate,
     * same reason field, same service.
     *
     * @template T of Action|PageAction
     *
     * @param  T  $action
     * @return T
     */
    public static function liftAction(Action|PageAction $action): Action|PageAction
    {
        return $action
            ->label('Lift')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Lift suspension')
            ->visible(fn (PayAtCollectionSuspension $record) => Gate::allows('update', $record))
            ->form([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
            ->action(function (PayAtCollectionSuspension $record, array $data): void {
                try {
                    (new PayAtCollectionSuspensions)->lift($record->id, (int) Auth::id(), (string) $data['reason']);
                    Notification::make()->title('Pay at collection reinstated')->success()->send();
                } catch (CashRefused $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    private static function staffEmail(?int $userId): ?string
    {
        return $userId === null ? null : User::query()->whereKey($userId)->value('email');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayAtCollectionSuspensions::route('/'),
            'view' => Pages\ViewPayAtCollectionSuspension::route('/{record}'),
        ];
    }
}
