<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Filament\Support\StatusTabs;
use App\Models\Company;
use Filament\Resources\Pages\ListRecords;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    public function getSubheading(): string
    {
        return 'Trade accounts and the people who can order on them. Open a company to manage its members and invitations.';
    }

    public function getTabs(): array
    {
        $groups = [];
        foreach (CompanyResource::STATUSES as $status => $label) {
            $groups[$status] = [$label, [$status], CompanyResource::statusColor($status)];
        }

        return StatusTabs::groups(Company::class, $groups);
    }
}
