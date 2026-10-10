<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerUserResource\Pages;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\B2bApplication;
use App\Models\User;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\RepeatableEntry;
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
    use SentenceCaseLabels;

    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Customer users';

    protected static ?string $modelLabel = 'customer user';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'customer-users';

    protected static ?string $recordTitleAttribute = 'email';

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereDoesntHave('roles')->with('companies')
            ->withExists(['tradeApplications as has_open_application' => fn (Builder $query) => $query->whereIn('status', B2bApplication::OPEN_STATUSES)]);
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
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Customer account')
                            ->icon('heroicon-o-user')
                            ->schema([
                                TextEntry::make('full_name')->label('Name')
                                    ->state(fn (User $record): string => trim("{$record->first_name} {$record->last_name}"))
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold')
                                    ->columnSpanFull(),
                                TextEntry::make('first_name')->label('First name'),
                                TextEntry::make('last_name')->label('Last name'),
                                TextEntry::make('email')->copyable()->columnSpanFull(),
                            ])
                            ->columns(2),

                        Section::make('Company memberships')
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                TextEntry::make('memberships')->hiddenLabel()
                                    ->state(fn (User $record): array => self::memberships($record) ?: ['No company — '.lcfirst(self::customerKind($record))])
                                    ->listWithLineBreaks(),
                            ]),

                        Section::make('Trade applications')
                            ->icon('heroicon-o-clipboard-document-check')
                            ->schema([
                                RepeatableEntry::make('tradeApplications')->hiddenLabel()->schema([
                                    TextEntry::make('company_name')->label('Company')
                                        ->url(fn (B2bApplication $record): string => TradeApplicationResource::getUrl('view', ['record' => $record]))
                                        ->color('primary'),
                                    TextEntry::make('status')->badge()
                                        ->formatStateUsing(fn (string $state): string => TradeApplicationResource::statusOptions()[$state] ?? $state)
                                        ->color(fn (string $state): string => TradeApplicationResource::statusColor($state)),
                                    TextEntry::make('submitted_at')->label('Submitted')->dateTime(),
                                ])->columns(3)->placeholder('No trade applications.'),
                            ]),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                TextEntry::make('status')->badge()
                                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                                    ->color(fn (string $state): string => self::statusColor($state)),
                                TextEntry::make('kind')->label('Customer type')
                                    ->state(fn (User $record): string => $record->companies->isNotEmpty() ? 'Trade account member' : self::customerKind($record)),
                            ]),

                        Section::make('Activity')
                            ->schema([
                                TextEntry::make('email_verified_at')->label('Email confirmed')->dateTime()->placeholder('Not confirmed'),
                                TextEntry::make('last_login_at')->label('Last sign-in')->dateTime()->placeholder('Never'),
                                TextEntry::make('created_at')->label('Account created')->dateTime(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Name')
                    ->state(fn (User $record): string => trim("{$record->first_name} {$record->last_name}"))
                    ->weight('medium')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('email')->searchable()->sortable()->copyable(),
                TextColumn::make('account')->label('Companies')->badge()
                    ->state(fn (User $record): array => $record->companies->pluck('name')->all() ?: [self::customerKind($record)])
                    ->color(fn (string $state, User $record): string => $record->companies->isNotEmpty() ? 'primary' : ($state === self::PENDING_APPLICANT ? 'warning' : 'gray')),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('last_login_at')->label('Last sign-in')->since()->placeholder('Never')->sortable()->toggleable(),
                TextColumn::make('created_at')->label('Joined')->date('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([ViewAction::make()->iconButton()->tooltip('View')])
            ->striped()
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateHeading('No customer users')
            ->emptyStateDescription('People who register on the storefront or join a trade account appear here.');
    }

    /** users.status */
    public const STATUSES = [
        'active' => 'Active',
        'pending' => 'Pending',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
    ];

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'pending' => 'warning',
            'suspended' => 'danger',
            default => 'gray',
        };
    }

    public const PENDING_APPLICANT = 'Trade applicant — pending';

    /**
     * A user with no company: "Trade applicant — pending" while they have an
     * open application (05.13 §4.1), otherwise a public customer.
     */
    public static function customerKind(User $user): string
    {
        $open = $user->getAttribute('has_open_application')
            ?? $user->tradeApplications()->whereIn('status', B2bApplication::OPEN_STATUSES)->exists();

        return $open ? self::PENDING_APPLICANT : 'Public customer';
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
