<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Domain\Identity\CompanyMemberService;
use App\Domain\Identity\CompanyMemberSettings;
use App\Filament\Support\CompanyMemberFields;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Members';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('email')->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('pivot.role')->label('Role'),
            TextColumn::make('pivot.order_limit_minor')->label('Order limit (pence)')->placeholder('No limit'),
            TextColumn::make('pivot.requires_approval')->label('Requires approval')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
        ])->actions([
            Action::make('changeMembership')->label('Change role / limit')
                ->visible(fn (): bool => Gate::allows('manageMembers', $this->company()))
                ->fillForm(fn (User $record): array => CompanyMemberService::settings(
                    CompanyUser::query()->where('company_id', $this->company()->id)->where('user_id', $record->id)->firstOrFail()))
                ->form(CompanyMemberFields::settings())
                ->action(function (User $record, array $data): void {
                    app(CompanyMemberService::class)->update($this->company(), $record, $this->actor(), CompanyMemberSettings::from($data));
                }),
            Action::make('removeMember')->label('Remove')->color('danger')->requiresConfirmation()
                ->modalDescription('Remove this membership. The last active owner of an approved or suspended company must remain.')
                ->visible(fn (): bool => Gate::allows('manageMembers', $this->company()))
                ->action(fn (User $record) => app(CompanyMemberService::class)->remove($this->company(), $record, $this->actor())),
        ]);
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
