<?php

namespace App\Filament\Resources;

use App\Domain\Purchasing\ReorderSettingsService;
use App\Filament\Resources\ReorderSettingResource\Pages;
use App\Models\Location;
use App\Models\ReorderSetting;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** 05.7 §10.7 — where purchasing opts a SKU into reorder suggestions. */
class ReorderSettingResource extends Resource
{
    protected static ?string $model = ReorderSetting::class;

    protected static ?string $slug = 'reorder-settings';

    protected static ?string $navigationLabel = 'Reorder settings';

    protected static ?string $pluralModelLabel = 'Reorder settings';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Purchasing';

    /** @return Builder<ReorderSetting> */
    public static function getEloquentQuery(): Builder
    {
        return app(ReorderSettingsService::class)->query()->with(['sku', 'location']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('sku.sku_code')->label('SKU')->searchable(),
            TextColumn::make('location.code')->label('Location'),
            TextColumn::make('available_base_qty')->label('Available')->alignEnd()->sortable(),
            TextColumn::make('incoming_base_qty')->label('On order')->alignEnd()->sortable(),
            TextColumn::make('reorder_point_base_qty')->label('Reorder point')->alignEnd()->sortable()
                ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Not set' : (string) $state),
            TextColumn::make('reorder_qty_base_qty')->label('Reorder qty')->alignEnd()->sortable(),
        ])->filters([
            TernaryFilter::make('has_reorder_point')->label('Reorder point')
                ->trueLabel('Set')->falseLabel('Not set')
                ->queries(
                    true: fn (Builder $query) => $query->where('reorder_point_base_qty', '>', 0),
                    false: fn (Builder $query) => $query->where('reorder_point_base_qty', 0),
                ),
            SelectFilter::make('location_id')->label('Location')
                ->options(fn (): array => Location::query()->where('is_sellable', true)->orderBy('name')->pluck('name', 'id')->all()),
        ])->actions([
            self::editAction(),
        ])->defaultSort('sku_id');
    }

    /**
     * The one edit path, shared with the Reorder suggestions rows: any
     * record carrying sku_id, location_id and the two reorder columns.
     */
    public static function editAction(): Action
    {
        return Action::make('editReorderLevels')
            ->label('Edit reorder levels')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => Gate::allows('update', ReorderSetting::class))
            ->modalDescription('Units, not packs. A reorder point of 0 stops suggestions for this SKU here.')
            ->fillForm(fn (Model $record): array => [
                'reorder_point_base_qty' => $record->getAttribute('reorder_point_base_qty'),
                'reorder_qty_base_qty' => $record->getAttribute('reorder_qty_base_qty'),
            ])
            ->form([
                TextInput::make('reorder_point_base_qty')->label('Reorder point (units)')
                    ->helperText('Suggest a reorder when available + on order falls to this.')
                    ->integer()->minValue(0)->maxValue(2147483647)->required(),
                TextInput::make('reorder_qty_base_qty')->label('Reorder quantity (units)')
                    ->helperText('The least a suggestion will ever propose.')
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
