<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Domain\Identity\CompanyInvitationService;
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
use Illuminate\Support\Facades\Gate;

class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('email')->searchable(),
            TextColumn::make('role'),
            TextColumn::make('order_limit_minor')->label('Order limit')->placeholder('No limit')
                ->formatStateUsing(fn (?int $state): ?string => MoneyFormatter::minor($state)),
            TextColumn::make('expires_at')->dateTime(),
            TextColumn::make('state')->state(fn (CompanyInvitation $record): string => $record->accepted_at !== null
                ? 'Accepted' : ($record->revoked_at !== null ? 'Revoked' : ($record->isOpen() ? 'Invited' : 'Expired'))),
        ])->headerActions([
            Action::make('invite')->label('Invite member')
                ->visible(fn (): bool => Gate::allows('create', [CompanyInvitation::class, $this->company()]))
                ->form(CompanyMemberFields::invitation())
                ->action(function (array $data): void {
                    app(CompanyInvitationService::class)->invite($this->company(), $this->actor(), $data['email'], $data['first_name'], $data['last_name'], CompanyMemberSettings::from($data));
                }),
        ])->actions([
            Action::make('resend')->requiresConfirmation()
                ->visible(fn (CompanyInvitation $record): bool => Gate::allows('manage', $record))
                ->action(fn (CompanyInvitation $record) => app(CompanyInvitationService::class)->resend($record, $this->actor())),
            Action::make('revoke')->color('danger')->requiresConfirmation()
                ->visible(fn (CompanyInvitation $record): bool => Gate::allows('manage', $record))
                ->action(fn (CompanyInvitation $record) => app(CompanyInvitationService::class)->revoke($record, $this->actor())),
        ])->defaultSort('created_at', 'desc');
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
