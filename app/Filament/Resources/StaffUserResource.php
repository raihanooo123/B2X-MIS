<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffUserResource\Pages;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StaffUserResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Staff';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'staff';

    protected static ?string $recordTitleAttribute = 'email';

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('roles')->with('roles');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('first_name')->label('First name')->required()->maxLength(100),
            TextInput::make('last_name')->label('Last name')->required()->maxLength(100),
            TextInput::make('email')->email()->required()->maxLength(255)->unique(User::class, 'email'),
            Select::make('role_codes')->label('Staff roles')
                ->multiple()->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'code')->all())
                ->required()->helperText('The new account starts pending. A password setup link is emailed after creation.'),
        ])->columns(2);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Staff account')->schema([
                TextEntry::make('first_name')->label('First name'),
                TextEntry::make('last_name')->label('Last name'),
                TextEntry::make('email'),
                TextEntry::make('status')->badge(),
                TextEntry::make('roles.name')->label('Roles')->badge(),
                TextEntry::make('two_factor_enabled')->label('Two-factor authentication')->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Not enrolled'),
                TextEntry::make('created_at')->label('Created')->dateTime(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('first_name')->label('First name')->searchable()->sortable(),
            TextColumn::make('last_name')->label('Last name')->searchable()->sortable(),
            TextColumn::make('email')->searchable()->sortable(),
            TextColumn::make('roles.name')->label('Roles')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('two_factor_enabled')->label('2FA')->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Not enrolled'),
        ])->actions([ViewAction::make()])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffUsers::route('/'),
            'create' => Pages\CreateStaffUser::route('/create'),
            'view' => Pages\ViewStaffUser::route('/{record}'),
        ];
    }
}
