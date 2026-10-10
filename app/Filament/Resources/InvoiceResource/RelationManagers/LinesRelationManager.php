<?php

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Domain\Billing\Documents\InvoiceDocumentBuilder;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Support\MoneyFormatter;
use App\Models\OrderLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The document's lines, as snapshotted on the order (CLAUDE.md invariant
 * 4): read-only, and shown to whoever may view the invoice (InvoicePolicy).
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'orderLines';

    protected static ?string $title = 'Lines';

    protected static ?string $icon = 'heroicon-o-queue-list';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return InvoiceResource::canView($ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_snapshot')->label('Description')->weight('medium')->wrap()
                    ->description(fn (OrderLine $record): string => $record->sku_code_snapshot),
                TextColumn::make('quantity')->label('Quantity')
                    ->state(fn (OrderLine $record): string => "{$record->pack_qty} × {$record->pack_label_snapshot}")
                    ->description(fn (OrderLine $record): string => "{$record->base_qty} units"),
                TextColumn::make('unit_price_net_e4')->label('Unit net')->alignEnd()
                    ->formatStateUsing(fn (int $state): ?string => MoneyFormatter::e4($state)),
                TextColumn::make('tax_rate_bp')->label('VAT rate')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => InvoiceDocumentBuilder::percent($state)),
                TextColumn::make('line_net_minor')->label('Net')->alignEnd()->weight('medium')
                    ->formatStateUsing(fn (int $state): ?string => MoneyFormatter::minor($state)),
                TextColumn::make('line_tax_minor')->label('VAT')->alignEnd()
                    ->formatStateUsing(fn (int $state): ?string => MoneyFormatter::minor($state)),
            ])
            ->paginated(false);
    }
}
