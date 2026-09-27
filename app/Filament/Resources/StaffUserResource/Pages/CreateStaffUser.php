<?php

namespace App\Filament\Resources\StaffUserResource\Pages;

use App\Domain\Identity\StaffOnboardingService;
use App\Filament\Resources\StaffUserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStaffUser extends CreateRecord
{
    protected static string $resource = StaffUserResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        return app(StaffOnboardingService::class)->create($data, $actor);
    }

    protected function getRedirectUrl(): string
    {
        return StaffUserResource::getUrl('view', ['record' => $this->record]);
    }
}
