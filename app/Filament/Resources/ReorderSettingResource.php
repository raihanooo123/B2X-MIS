<?php

namespace App\Filament\Resources;

use App\Domain\Purchasing\ReorderSettingsService;
use App\Filament\Resources\ReorderSettingResource\Pages;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Location;
use App\Models\ReorderSetting;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** 05.7 §10.7 — where purchasing opts a SKU into reorder suggestions. */
class ReorderSettingResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = ReorderSetting::class;

    protected static ?string $slug = 'reorder-settings';

    protected static ?string $navigationLabel = 'Reorder settings';

    protected static ?string $pluralModelLabel = 'Reorder settings';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 40;

    /** @return Builder<ReorderSetting> */
    public static function getEloquentQuery(): Builder
    {
        return app(ReorderSettingsService::class)->query()->with(['sku.product', 'location']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku.sku_code')
                    ->label('SKU')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->description(fn (ReorderSetting $record): ?string => $record->sku?->product?->name)
                    ->searchable(),
                TextColumn::make('location.name')
                    ->label('Location')
                    ->toggleable(),
                TextColumn::make('available_base_qty')
                    ->label('Available')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('incoming_base_qty')
                    ->label('On order')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('reorder_point_base_qty')
                    ->label('Reorder point')
                    ->alignEnd()
                    ->sortable()
                    ->weight(fn (int $state): string => $state === 0 ? 'normal' : 'semibold')
                    ->color(fn (int $state): ?string => $state === 0 ? 'gray' : null)
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Not set' : (string) $state),
                TextColumn::make('reorder_qty_base_qty')
                    ->label('Reorder quantity')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('location_id')->label('Location')
                    ->options(fn (): array => Location::query()->where('is_sellable', true)->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->actions([
                self::editAction(),
            ])
            ->striped()
            ->defaultSort('sku_id')
            ->emptyStateIcon('heroicon-o-adjustments-horizontal')
            ->emptyStateHeading('No stock-tracked SKUs')
            ->emptyStateDescription('Active, stock-tracked SKUs appear here once per sellable location.');
    }

    /**
     * The one edit path, shared with the Reorder suggestions rows: any
     * record carrying sku_id, location_id and the two reorder columns.
     */
    public static function editAction(): Action
    {
        return Action::make('editReorderLevels')
            ->label('Set levels')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading('Reorder levels')
            ->visible(fn (): bool => Gate::allows('update', ReorderSetting::class))
            ->modalDescription('In base units, not packs. Set the reorder point to 0 to stop suggestions for this SKU at this location.')
            ->fillForm(fn (Model $record): array => [
                'reorder_point_base_qty' => $record->getAttribute('reorder_point_base_qty'),
                'reorder_qty_base_qty' => $record->getAttribute('reorder_qty_base_qty'),
            ])
            ->form([
                TextInput::make('reorder_point_base_qty')->label('Reorder point (units)')
                    ->helperText('Suggest buying more when available stock plus stock on order falls to this.')
                    ->integer()->minValue(0)->maxValue(2147483647)->required(),
                TextInput::make('reorder_qty_base_qty')->label('Reorder quantity (units)')
                    ->helperText('The smallest amount a suggestion will propose.')
                    ->integer()->minValue(0)->maxValue(2147483647)->required(),
            ])
            ->action(function (Action $action, Model $record, array $data): void {
                Gate::authorize('update', ReorderSetting::class);
                try {
                    app(ReorderSettingsService::class)->set(
                        (int) $record->getAttribute('sku_id'),
                        (int) $record->getAttribute('location_id'),
                        (int) $data['reorder_point_base_qty'],
                        (int) $data['reorder_qty_base_qty'],
                    );
                } catch (ValidationException $e) {
                    Notification::make()->title('Not saved')->body(implode(' ', $e->validator->errors()->all()))->danger()->send();
                    $action->halt();
                }
                Notification::make()->title('Reorder levels saved')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReorderSettings::route('/')];
    }
}
