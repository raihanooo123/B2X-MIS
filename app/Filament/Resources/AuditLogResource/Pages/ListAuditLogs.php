<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    public function getSubheading(): string
    {
        return 'A permanent record of sign-ins, permission changes, money decisions and stock adjustments. Nothing here can be changed.';
    }

    /**
     * Families grouped by what staff look for. No counts: the log only
     * grows, and a count per page load would scan it.
     */
    public function getTabs(): array
    {
        $tab = fn (string $label, array $families): Tab => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('event_family', $families));

        return [
            'all' => Tab::make('All'),
            'security' => $tab('Sign-in and access', ['auth', 'permission', 'rep_session']),
            'money' => $tab('Credit and prices', ['credit_limit', 'price', 'price_override', 'discount_authority', 'fee_waiver']),
            'stock' => $tab('Stock and returns', ['stock_adjustment', 'rma_disposition']),
            'configuration' => $tab('Settings', ['configuration']),
        ];
    }
}
