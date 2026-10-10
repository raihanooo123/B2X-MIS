<?php

namespace App\Filament\Resources\StaffUserResource\Pages;

use App\Filament\Resources\StaffUserResource;
use App\Filament\Support\StatusTabs;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaffUsers extends ListRecords
{
    protected static string $resource = StaffUserResource::class;

    public function getSubheading(): string
    {
        return 'People who work in the admin panel and the warehouse. Everyone here must use two-factor authentication.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add staff member')->icon('heroicon-m-plus')];
    }

    public function getTabs(): array
    {
        $groups = [];
        foreach (StaffUserResource::STATUSES as $status => $label) {
            $groups[$status] = [$label, [$status], StaffUserResource::statusColor($status)];
        }

        // Counted over staff only, as the list: users holding a role.
        return StatusTabs::groups(User::query()->whereHas('roles'), $groups);
    }
}
