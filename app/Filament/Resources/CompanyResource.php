<?php

namespace App\Filament\Resources;

use App\Domain\Billing\PaymentTerms;
use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource\RelationManagers;
use App\Filament\Support\MoneyFormatter;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Company;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Admin-only directory; member writes go through the same services as the owner UI. */
class CompanyResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Company::class;

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Company members';

    protected static ?string $recordTitleAttribute = 'name';

    /** companies.status */
    public const STATUSES = [
        'approved' => 'Approved',
        'applied' => 'Applied',
        'suspended' => 'Suspended',
        'rejected' => 'Rejected',
        'closed' => 'Closed',
    ];

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'applied' => 'warning',
            'suspended', 'rejected' => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('priceTier')->withCount('users'))
            ->columns([
                TextColumn::make('name')->label('Company')
                    ->weight('medium')
                    ->description(fn (Company $record): string => $record->account_code)
                    ->searchable(['name', 'account_code'])
                    ->sortable(),
                TextColumn::make('users_count')->label('Members')->alignEnd()->sortable(),
                TextColumn::make('priceTier.name')->label('Price tier')->placeholder('—')->toggleable(),
                TextColumn::make('payment_terms')->label('Terms')
                    ->formatStateUsing(fn (string $state): string => PaymentTerms::tryFrom($state)?->label() ?? $state)
                    ->toggleable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('accounts_email')->label('Accounts email')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([ViewAction::make()->iconButton()->tooltip('View members')])
            ->striped()
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-building-office')
            ->emptyStateHeading('No trade accounts yet')
            ->emptyStateDescription('A company appears here once its trade application is approved.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Company')
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                TextEntry::make('name')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold')
                                    ->columnSpanFull(),
                                TextEntry::make('account_code')->label('Account code')->fontFamily('mono')->copyable(),
                                TextEntry::make('vat_number')->label('VAT number')->placeholder('Not given'),
                                TextEntry::make('accounts_email')->label('Accounts email')->placeholder('Not given')->copyable(),
                            ])
                            ->columns(2),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                TextEntry::make('status')->badge()
                                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                                    ->color(fn (string $state): string => self::statusColor($state)),
                            ]),

                        Section::make('Trading terms')
                            ->schema([
                                TextEntry::make('priceTier.name')->label('Price tier')->placeholder('—'),
                                TextEntry::make('payment_terms')->label('Payment terms')
                                    ->formatStateUsing(fn (string $state): string => PaymentTerms::tryFrom($state)?->label() ?? $state),
                                TextEntry::make('credit_limit_minor')->label('Credit limit')
                                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'No credit' : (MoneyFormatter::minor($state) ?? '—')),
                            ]),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\MembersRelationManager::class, RelationManagers\InvitationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'view' => Pages\ViewCompany::route('/{record}'),
            // 05.2 §18.3: /admin/companies/{id}/credit, accounts/admin (CompanyCredit::canAccess).
            'credit' => Pages\CompanyCredit::route('/{record}/credit'),
        ];
    }
}
