<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffUserResource\Pages;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Group as FormGroup;
use Filament\Forms\Components\Section as FormSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Group;
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

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?string $recordTitleAttribute = 'email';

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('roles')->with('roles');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                FormGroup::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        FormSection::make('Person')
                            ->icon('heroicon-o-user')
                            ->schema([
                                TextInput::make('first_name')->label('First name')->required()->maxLength(100),
                                TextInput::make('last_name')->label('Last name')->required()->maxLength(100),
                                TextInput::make('email')->email()->required()->maxLength(255)->unique(User::class, 'email')
                                    ->helperText('Their work email. The setup link is sent here.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
                FormGroup::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        FormSection::make('Access')
                            ->schema([
                                Select::make('role_codes')->label('Staff roles')
                                    ->multiple()->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'code')->all())
                                    ->required()->helperText('The new account starts pending. A password setup link is emailed after creation.'),
                            ]),
                    ]),
            ]);
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Staff account')
                            ->icon('heroicon-o-user')
                            ->schema([
                                TextEntry::make('full_name')->label('Name')
                                    ->state(fn (User $record): string => trim("{$record->first_name} {$record->last_name}"))
                                    ->size(TextEntry\TextEntrySize::Large)->weight('semibold')->columnSpanFull(),
                                TextEntry::make('first_name')->label('First name'),
                                TextEntry::make('last_name')->label('Last name'),
                                TextEntry::make('email')->copyable()->columnSpanFull(),
                            ])
                            ->columns(2),
                        Section::make('Roles')
                            ->icon('heroicon-o-key')
                            ->description('What this person can see and do. Use "Grant role" or "Revoke role" above to change it.')
                            ->schema([
                                TextEntry::make('roles.name')->hiddenLabel()->badge()->color('primary'),
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
                            ]),
                        Section::make('Security')
                            ->schema([
                                TextEntry::make('two_factor_enabled')->label('Two-factor authentication')->badge()
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Not enrolled')
                                    ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
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
                TextColumn::make('name')->label('Name')->weight('medium')
                    ->state(fn (User $record): string => trim("{$record->first_name} {$record->last_name}"))
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('roles.name')->label('Roles')->badge()->color('primary'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('two_factor_enabled')->label('2FA')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Not enrolled')
                    ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                TextColumn::make('last_login_at')->label('Last sign-in')->since()->placeholder('Never')->sortable()->toggleable(),
                TextColumn::make('created_at')->label('Added')->date('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([ViewAction::make()->iconButton()->tooltip('View')])
            ->striped()
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading('No staff here');
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
