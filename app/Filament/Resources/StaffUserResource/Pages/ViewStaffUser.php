<?php

namespace App\Filament\Resources\StaffUserResource\Pages;

use App\Domain\Identity\StaffOnboardingService;
use App\Domain\Identity\StaffRoleService;
use App\Domain\Identity\StaffSuspensionService;
use App\Filament\Resources\StaffUserResource;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewStaffUser extends ViewRecord
{
    protected static string $resource = StaffUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resendSetupLink')
                ->label('Resend setup link')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('resendStaffOnboarding', $this->staff()))
                ->action(function (): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        abort(403);
                    }

                    try {
                        app(StaffOnboardingService::class)->resendLink($this->staff(), $actor);
                        Notification::make()->title('Setup link sent')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title(collect($exception->errors())->flatten()->first() ?? 'Unable to send setup link')->danger()->send();
                    }
                }),
            Action::make('grantRole')
                ->label('Grant role')
                ->visible(fn (): bool => Gate::allows('manageStaffRoles', $this->staff()))
                ->form([
                    Select::make('role')->label('Role')->required()
                        ->options(fn (): array => $this->roleOptions(held: false)),
                ])
                ->action(fn (array $data) => $this->changeRole('grant', $data)),
            Action::make('revokeRole')
                ->label('Revoke role')
                ->color('danger')
                ->visible(fn (): bool => Gate::allows('manageStaffRoles', $this->staff()))
                ->modalDescription('A staff member keeps at least one role, and the last active administrator keeps the admin role.')
                ->form([
                    Select::make('role')->label('Role')->required()
                        ->options(fn (): array => $this->roleOptions(held: true)),
                ])
                ->action(fn (array $data) => $this->changeRole('revoke', $data)),
            Action::make('suspend')
                ->label('Suspend')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Sign-in is blocked and every open session ends at once. The account keeps its roles.')
                ->visible(fn (): bool => Gate::allows('suspendStaff', $this->staff()))
                ->action(fn () => $this->changeStatus('suspend')),
            Action::make('reinstate')
                ->label('Reinstate')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('reinstateStaff', $this->staff()))
                ->action(fn () => $this->changeStatus('reinstate')),
        ];
    }

    private function changeStatus(string $operation): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        $service = app(StaffSuspensionService::class);

        try {
            $operation === 'suspend'
                ? $service->suspend($this->staff(), $actor)
                : $service->reinstate($this->staff(), $actor);
            Notification::make()->title($operation === 'suspend' ? 'Staff member suspended' : 'Staff member reinstated')->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first() ?? 'Unable to change status')->danger()->send();
        }

        $this->staff()->refresh();
    }

    /** @return array<string, string> */
    private function roleOptions(bool $held): array
    {
        $heldIds = $this->staff()->roles()->pluck('roles.id')->all();

        return Role::query()
            ->whereIn('code', StaffOnboardingService::ROLES)
            ->when($held, fn ($query) => $query->whereIn('id', $heldIds), fn ($query) => $query->whereNotIn('id', $heldIds))
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();
    }

    /** @param array<string, mixed> $data */
    private function changeRole(string $operation, array $data): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        $role = is_string($data['role'] ?? null) ? $data['role'] : '';
        $service = app(StaffRoleService::class);

        try {
            $operation === 'grant'
                ? $service->grant($this->staff(), $role, $actor)
                : $service->revoke($this->staff(), $role, $actor);
            Notification::make()->title($operation === 'grant' ? 'Role granted' : 'Role revoked')->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first() ?? 'Unable to change role')->danger()->send();
        }

        $this->staff()->refresh();
    }

    private function staff(): User
    {
        $record = $this->getRecord();
        if (! $record instanceof User) {
            throw new \LogicException('This page requires a staff account.');
        }

        return $record;
    }
}
