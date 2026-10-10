<?php

namespace App\Filament\Resources\CustomerUserResource\Pages;

use App\Domain\Identity\CustomerSuspensionService;
use App\Filament\Resources\CustomerUserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewCustomerUser extends ViewRecord
{
    protected static string $resource = CustomerUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-m-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Sign-in is blocked and every open session ends at once. Their companies, roles and credit are unchanged. The last active owner of an approved or suspended company cannot be suspended.')
                ->visible(fn (): bool => Gate::allows('suspendCustomer', $this->customer()))
                ->action(fn () => $this->changeStatus('suspend')),
            Action::make('reinstate')
                ->label('Reinstate')
                ->icon('heroicon-m-arrow-uturn-left')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('reinstateCustomer', $this->customer()))
                ->action(fn () => $this->changeStatus('reinstate')),
        ];
    }

    private function changeStatus(string $operation): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        $service = app(CustomerSuspensionService::class);

        try {
            $operation === 'suspend'
                ? $service->suspend($this->customer(), $actor)
                : $service->reinstate($this->customer(), $actor);
            Notification::make()->title($operation === 'suspend' ? 'Customer user suspended' : 'Customer user reinstated')->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first() ?? 'Unable to change status')->danger()->send();
        }

        $this->customer()->refresh();
    }

    private function customer(): User
    {
        $record = $this->getRecord();
        if (! $record instanceof User) {
            throw new \LogicException('This page requires a customer account.');
        }

        return $record;
    }
}
