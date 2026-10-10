<?php

namespace App\Filament\Resources\NotificationLogResource\Pages;

use App\Filament\Resources\NotificationLogResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListNotificationLogs extends ListRecords
{
    protected static string $resource = NotificationLogResource::class;

    public function getSubheading(): string
    {
        return 'Every email sent to customers and staff, and whether it arrived. Check "Undelivered invoices" regularly.';
    }

    /**
     * No counts on these tabs: the log grows with every email, and a count
     * per page load would scan it.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'undelivered_money' => Tab::make('Undelivered invoices')
                ->icon('heroicon-m-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query): Builder => NotificationLogResource::undeliveredMoney($query)),
            'problems' => Tab::make('Problems')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', NotificationLogResource::PROBLEM_STATUSES)),
            'delivered' => Tab::make('Delivered')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'delivered')),
            'pending' => Tab::make('Waiting')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', ['queued', 'sent'])),
        ];
    }
}
