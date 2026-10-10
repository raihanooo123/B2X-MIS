<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Domain\Identity\CompanyInvitationService;
use App\Domain\Identity\CompanyMemberRole;
use App\Domain\Identity\CompanyMemberSettings;
use App\Filament\Support\CompanyMemberFields;
use App\Filament\Support\MoneyFormatter;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    protected static ?string $icon = 'heroicon-o-envelope';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Company) {
            return null;
        }

        $open = $ownerRecord->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();

        return $open > 0 ? (string) $open : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->weight('medium')
                    ->description(fn (CompanyInvitation $record): string => trim("{$record->first_name} {$record->last_name}"))
                    ->searchable(),
                TextColumn::make('role')->badge()
                    ->formatStateUsing(fn (string $state): string => CompanyMemberRole::options()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === 'owner' ? 'primary' : 'gray'),
                TextColumn::make('order_limit_minor')->label('Order limit')->placeholder('No limit')
                    ->formatStateUsing(fn (?int $state): ?string => MoneyFormatter::minor($state))
                    ->alignEnd(),
                TextColumn::make('expires_at')->label('Expires')->dateTime()
                    ->description(fn (CompanyInvitation $record): string => $record->expires_at->diffForHumans()),
                TextColumn::make('state')->badge()
                    ->state(fn (CompanyInvitation $record): string => $record->accepted_at !== null
                        ? 'Accepted' : ($record->revoked_at !== null ? 'Revoked' : ($record->isOpen() ? 'Invited' : 'Expired')))
                    ->color(fn (string $state): string => match ($state) {
                        'Accepted' => 'success',
                        'Invited' => 'info',
                        'Revoked' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->headerActions([
                Action::make('invite')->label('Invite member')->icon('heroicon-m-plus')
                    ->visible(fn (): bool => Gate::allows('create', [CompanyInvitation::class, $this->company()]))
                    ->form(CompanyMemberFields::invitation())
                    ->action(function (array $data): void {
                        app(CompanyInvitationService::class)->invite($this->company(), $this->actor(), $data['email'], $data['first_name'], $data['last_name'], CompanyMemberSettings::from($data));
                    }),
            ])
            ->actions([
                Action::make('resend')->requiresConfirmation()
                    ->iconButton()->icon('heroicon-o-paper-airplane')->tooltip('Resend invitation')
                    ->visible(fn (CompanyInvitation $record): bool => Gate::allows('manage', $record))
                    ->action(fn (CompanyInvitation $record) => app(CompanyInvitationService::class)->resend($record, $this->actor())),
                Action::make('revoke')->color('danger')->requiresConfirmation()
                    ->iconButton()->icon('heroicon-o-x-circle')->tooltip('Revoke invitation')
                    ->visible(fn (CompanyInvitation $record): bool => Gate::allows('manage', $record))
                    ->action(fn (CompanyInvitation $record) => app(CompanyInvitationService::class)->revoke($record, $this->actor())),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-envelope')
            ->emptyStateHeading('No invitations')
            ->emptyStateDescription('Invite someone to order on this account.');
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
