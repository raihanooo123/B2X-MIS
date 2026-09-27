<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerUserResource\Pages;
use App\Models\User;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 05.13 §4.1–4.2: customer users — every user with no `role_user` row —
 * listed read-only with their company memberships (02 §4.4). The only
 * change made here is suspension (ViewCustomerUser). Staff are managed
 * from StaffUserResource and never appear here.
 *
 * The model is shared with StaffUserResource, so the resource names its
 * own UserPolicy abilities rather than the model's default view/viewAny.
 */
class CustomerUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Customer users';

    protected static ?string $modelLabel = 'customer user';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'customer-users';

    protected static ?string $recordTitleAttribute = 'email';

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereDoesntHave('roles')->with('companies');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAnyCustomers', User::class);
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('viewCustomer', $record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Customer account')->schema([
                TextEntry::make('first_name')->label('First name'),
                TextEntry::make('last_name')->label('Last name'),
                TextEntry::make('email'),
                TextEntry::make('status')->badge(),
                TextEntry::make('email_verified_at')->label('Email verified')->dateTime()->placeholder('Not verified'),
                TextEntry::make('last_login_at')->label('Last sign-in')->dateTime()->placeholder('Never'),
                TextEntry::make('created_at')->label('Created')->dateTime(),
            ])->columns(2),
            Section::make('Company memberships')->schema([
                TextEntry::make('memberships')->hiddenLabel()
                    ->state(fn (User $record): array => self::memberships($record))
                    ->listWithLineBreaks()
                    ->placeholder('No company — a public customer'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('first_name')->label('First name')->searchable()->sortable(),
            TextColumn::make('last_name')->label('Last name')->searchable()->sortable(),
            TextColumn::make('email')->searchable()->sortable(),
            TextColumn::make('companies.name')->label('Companies')->badge()->placeholder('Public customer'),
            TextColumn::make('status')->badge(),
        ])->actions([ViewAction::make()])->defaultSort('created_at', 'desc');
    }

    /** @return list<string> "Company (ACCOUNT) — role · company status" */
    private static function memberships(User $user): array
    {
        return array_values(DB::table('company_users')
            ->join('companies', 'companies.id', '=', 'company_users.company_id')
            ->where('company_users.user_id', $user->id)
            ->orderBy('companies.name')
            ->get(['companies.name', 'companies.account_code', 'companies.status', 'company_users.role'])
            ->map(fn (object $row): string => "{$row->name} ({$row->account_code}) — {$row->role} · company {$row->status}")
            ->all());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerUsers::route('/'),
            'view' => Pages\ViewCustomerUser::route('/{record}'),
        ];
    }
}
