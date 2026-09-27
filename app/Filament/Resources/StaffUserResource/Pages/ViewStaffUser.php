<?php

namespace App\Filament\Resources\StaffUserResource\Pages;

use App\Domain\Identity\StaffOnboardingService;
use App\Filament\Resources\StaffUserResource;
use App\Models\User;
use Filament\Actions\Action;
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
        ];
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
