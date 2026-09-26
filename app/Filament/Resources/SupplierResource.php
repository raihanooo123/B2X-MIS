<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Supplier')->schema([
                TextInput::make('code')->required()->maxLength(64)->unique(ignoreRecord: true),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('country_code')->label('Country code')->required()->length(2)->default('GB'),
                Select::make('default_currency')->label('Currency')->options(['GBP' => 'GBP'])->default('GBP')->required()
                    ->helperText('This first purchase-order workflow supports GBP only.'),
                Select::make('default_incoterm')->label('Incoterm')->options(array_combine(
                    ['EXW', 'FOB', 'CIF', 'CFR', 'DAP', 'DDP'],
                    ['EXW', 'FOB', 'CIF', 'CFR', 'DAP', 'DDP'],
                ))->default('FOB')->required(),
                Select::make('status')->options(['active' => 'Active', 'on_hold' => 'On hold', 'archived' => 'Archived'])->default('active')->required(),
                TextInput::make('lead_time_days')->label('Lead time (days)')->numeric()->integer()->minValue(0),
                TextInput::make('payment_terms')->maxLength(255),
                TextInput::make('contact_name')->maxLength(255),
                TextInput::make('contact_email')->email()->maxLength(255),
                TextInput::make('contact_phone')->maxLength(64),
                Textarea::make('note')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('country_code')->label('Country'),
            TextColumn::make('default_currency')->label('Currency'),
            TextColumn::make('status')->badge(),
        ])->actions([ViewAction::make(), EditAction::make()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'view' => Pages\ViewSupplier::route('/{record}'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
