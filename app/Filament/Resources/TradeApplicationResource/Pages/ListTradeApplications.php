<?php

namespace App\Filament\Resources\TradeApplicationResource\Pages;

use App\Filament\Resources\TradeApplicationResource;
use App\Filament\Support\StatusTabs;
use App\Models\B2bApplication;
use Filament\Resources\Pages\ListRecords;

class ListTradeApplications extends ListRecords
{
    protected static string $resource = TradeApplicationResource::class;

    public function getSubheading(): string
    {
        return 'Businesses asking for a trade account. Open applications, oldest first.';
    }

    /** The review queue first: open applications are the default tab. */
    public function getTabs(): array
    {
        $statuses = TradeApplicationResource::statusOptions();
        $groups = ['open' => ['Open', B2bApplication::OPEN_STATUSES, 'warning']];
        foreach (['approved', 'rejected', 'withdrawn'] as $status) {
            $groups[$status] = [$statuses[$status], [$status], TradeApplicationResource::statusColor($status)];
        }

        return StatusTabs::groups(B2bApplication::class, $groups);
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
