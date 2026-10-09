<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource\RelationManagers;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Company;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Admin-only directory; member writes go through the same services as the owner UI. */
class CompanyResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Company::class;

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Company members';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('account_code')->searchable(),
            TextColumn::make('status')->badge(),
        ])->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([TextEntry::make('name'), TextEntry::make('account_code'), TextEntry::make('status')]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\MembersRelationManager::class, RelationManagers\InvitationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCompanies::route('/'), 'view' => Pages\ViewCompany::route('/{record}')];
    }
}
