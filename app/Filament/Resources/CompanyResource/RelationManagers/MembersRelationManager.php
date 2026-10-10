<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Domain\Identity\CompanyMemberRole;
use App\Domain\Identity\CompanyMemberService;
use App\Domain\Identity\CompanyMemberSettings;
use App\Filament\Resources\CustomerUserResource;
use App\Filament\Support\CompanyMemberFields;
use App\Filament\Support\MoneyFormatter;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Members';

    protected static ?string $icon = 'heroicon-o-users';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return $ownerRecord instanceof Company ? (string) $ownerRecord->users()->count() : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Name')
                    ->state(fn (User $record): string => trim("{$record->first_name} {$record->last_name}"))
                    ->description(fn (User $record): string => $record->email)
                    ->weight('medium')
                    ->searchable(['first_name', 'last_name', 'email']),
                TextColumn::make('pivot.role')->label('Role')->badge()
                    ->formatStateUsing(fn (string $state): string => CompanyMemberRole::options()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === 'owner' ? 'primary' : 'gray'),
                TextColumn::make('pivot.order_limit_minor')->label('Order limit')->placeholder('No limit')
                    ->formatStateUsing(fn (?int $state): ?string => MoneyFormatter::minor($state))
                    ->alignEnd(),
                TextColumn::make('pivot.requires_approval')->label('Requires approval')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => CustomerUserResource::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => CustomerUserResource::statusColor($state)),
            ])
            ->actions([
                Action::make('changeMembership')->label('Change role / limit')
                    ->iconButton()->icon('heroicon-o-pencil-square')->tooltip('Change role or order limit')
                    ->visible(fn (): bool => Gate::allows('manageMembers', $this->company()))
                    ->fillForm(fn (User $record): array => CompanyMemberSettings::formValues(
                        CompanyUser::query()->where('company_id', $this->company()->id)->where('user_id', $record->id)->firstOrFail()))
                    ->form(CompanyMemberFields::settings())
                    ->action(function (User $record, array $data): void {
                        app(CompanyMemberService::class)->update($this->company(), $record, $this->actor(), CompanyMemberSettings::from($data));
                    }),
                Action::make('removeMember')->label('Remove')->color('danger')->requiresConfirmation()
                    ->iconButton()->icon('heroicon-o-user-minus')->tooltip('Remove from company')
                    ->modalDescription('Remove this membership. The last active owner of an approved or suspended company must remain.')
                    ->visible(fn (): bool => Gate::allows('manageMembers', $this->company()))
                    ->action(fn (User $record) => app(CompanyMemberService::class)->remove($this->company(), $record, $this->actor())),
            ])
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading('No members')
            ->emptyStateDescription('Invite someone from the Invitations tab.');
    }

    private function company(): Company
    {
        $company = $this->getOwnerRecord();
        assert($company instanceof Company);

        return $company;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
