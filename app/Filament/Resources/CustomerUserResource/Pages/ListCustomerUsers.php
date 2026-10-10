<?php

namespace App\Filament\Resources\CustomerUserResource\Pages;

use App\Filament\Resources\CustomerUserResource;
use App\Filament\Support\StatusTabs;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;

class ListCustomerUsers extends ListRecords
{
    protected static string $resource = CustomerUserResource::class;

    public function getSubheading(): string
    {
        return 'Everyone who shops with you: public customers, trade applicants and trade account members. Staff are under Settings.';
    }

    public function getTabs(): array
    {
        $groups = [];
        foreach (CustomerUserResource::STATUSES as $status => $label) {
            $groups[$status] = [$label, [$status], CustomerUserResource::statusColor($status)];
        }

        // Counted over customers only, as the list: users with no staff role.
        return StatusTabs::groups(User::query()->whereDoesntHave('roles'), $groups);
    }
}
