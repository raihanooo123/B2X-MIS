<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Filament\Support\MoneyFormatter;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sku;
use App\Models\Supplier;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'po_number';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Purchase order')->schema([
                Select::make('supplier_id')->label('Supplier')->options(fn (): array => Supplier::query()->where('status', 'active')->where('default_currency', 'GBP')->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                Select::make('location_id')->label('Receive at')->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                DatePicker::make('expected_at')->label('Expected delivery'),
                TextInput::make('supplier_reference')->label('Supplier reference')->maxLength(255),
                Textarea::make('note')->columnSpanFull(),
            ])->columns(2),
            Section::make('Goods')->description('Enter the supplier cost per base unit in GBP. The order can be received only after confirmation.')->schema([
                Repeater::make('lines')->hiddenLabel()->minItems(1)->defaultItems(1)->required()->schema([
                    Select::make('sku_id')->label('SKU')->options(fn (): array => Sku::query()->where('status', 'active')->where('is_stock_tracked', true)->orderBy('sku_code')->pluck('sku_code', 'id')->all())
                        ->searchable()->live()->afterStateUpdated(fn (Set $set) => $set('pack_id', null))->required(),
                    Select::make('pack_id')->label('Pack')->options(fn (Get $get): array => Pack::query()->where('sku_id', (int) $get('sku_id'))->orderBy('base_units')->get()->mapWithKeys(fn (Pack $pack) => [$pack->id => "{$pack->label} ({$pack->base_units} units)"])->all())->required(),
                    TextInput::make('pack_qty')->label('Packs')->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('unit_fob')->label('Cost per base unit')->prefix('£')->rule('regex:/^\d{1,7}(\.\d{1,4})?$/')->required()
                        ->helperText('Up to four decimal places; FOB cost, not a customer selling price.'),
                ])->columns(4)->reorderableWithButtons(),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Purchase order')->schema([
                TextEntry::make('po_number')->label('PO number'),
                TextEntry::make('status')->badge(),
                TextEntry::make('supplier.name')->label('Supplier'),
                TextEntry::make('location.name')->label('Receive at'),
                TextEntry::make('expected_at')->label('Expected')->date()->placeholder('Not set'),
                TextEntry::make('currency')->label('Currency'),
                TextEntry::make('goods_total_minor')->label('Goods total')->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state)),
                TextEntry::make('note')->placeholder('—'),
            ])->columns(4),
            InfolistSection::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->schema([
                    TextEntry::make('line_no')->label('#'),
                    TextEntry::make('sku_code_snapshot')->label('SKU'),
                    TextEntry::make('pack.label')->label('Pack'),
                    TextEntry::make('pack_qty')->label('Packs'),
                    TextEntry::make('base_qty')->label('Units'),
                    TextEntry::make('received_base_qty')->label('Received'),
                    TextEntry::make('unit_fob_e4')->label('Unit FOB')->formatStateUsing(fn (int $state) => MoneyFormatter::e4($state)),
                    TextEntry::make('line_fob_minor')->label('Line total')->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state)),
                ])->columns(8),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('po_number')->label('PO')->searchable()->sortable(),
            TextColumn::make('supplier.name')->label('Supplier')->searchable(),
            TextColumn::make('location.code')->label('Location'),
            TextColumn::make('status')->badge(),
            TextColumn::make('expected_at')->label('Expected')->date()->sortable(),
            TextColumn::make('goods_total_minor')->label('Goods total')->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state)),
        ])->actions([
            ViewAction::make(),
            EditAction::make()->visible(fn (PurchaseOrder $record): bool => $record->status === 'draft'),
        ])->defaultSort('created_at', 'desc');
    }

    public static function unitCostInput(PurchaseOrderLine $line): string
    {
        return intdiv($line->unit_fob_e4, 10000).'.'.str_pad((string) ($line->unit_fob_e4 % 10000), 4, '0', STR_PAD_LEFT);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'view' => Pages\ViewPurchaseOrder::route('/{record}'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
