<?php

namespace App\Filament\Resources;

use App\Domain\Collection\CashRefused;
use App\Domain\Collection\PayAtCollectionSuspensions;
use App\Filament\Resources\PayAtCollectionSuspensionResource\Pages;
use App\Models\PayAtCollectionSuspension;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
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
    protected static ?string $model = PayAtCollectionSuspension::class;

    protected static ?string $navigationGroup = 'Collections';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Pay at collection suspensions';

    protected static ?string $modelLabel = 'suspension';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user:id,email,first_name,last_name', 'company:id,name,account_code']))
            ->columns([
                TextColumn::make('customer')
                    ->state(fn (PayAtCollectionSuspension $record) => $record->company !== null
                        ? "{$record->company->name} ({$record->company->account_code})"
                        : $record->user?->email),
                TextColumn::make('reason')->badge()->formatStateUsing(fn (string $state) => $state === 'no_shows' ? 'No-shows' : 'Manual'),
                TextColumn::make('no_show_count')->label('No-shows')->placeholder('—'),
                TextColumn::make('suspended_at')->label('Suspended')->dateTime()->sortable(),
                TextColumn::make('note')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('lifted_at')->label('Lifted')->dateTime()->placeholder('Active'),
                TextColumn::make('lift_reason')->label('Lift reason')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('active')->label('Active')->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('lifted_at'),
                        false: fn (Builder $q) => $q->whereNotNull('lifted_at'),
                    ),
            ])
            ->actions([
                Action::make('lift')
                    ->label('Lift')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (PayAtCollectionSuspension $record) => Gate::allows('update', $record))
                    ->form([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(function (PayAtCollectionSuspension $record, array $data): void {
                        try {
                            (new PayAtCollectionSuspensions)->lift($record->id, (int) Auth::id(), (string) $data['reason']);
                            Notification::make()->title('Pay at collection reinstated')->success()->send();
                        } catch (CashRefused $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('suspended_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayAtCollectionSuspensions::route('/'),
        ];
    }
}
