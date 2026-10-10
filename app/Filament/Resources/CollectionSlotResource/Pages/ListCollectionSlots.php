<?php

namespace App\Filament\Resources\CollectionSlotResource\Pages;

use App\Domain\Collection\CollectionSlots;
use App\Filament\Resources\CollectionSlotResource;
use App\Filament\Support\StatusTabs;
use App\Models\CollectionSlot;
use App\Models\Location;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListCollectionSlots extends ListRecords
{
    protected static string $resource = CollectionSlotResource::class;

    /** 05.6 §7A.1: close every slot of one day at a location. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('closeDay')
                ->label('Close a day')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn () => Gate::allows('update', new CollectionSlot))
                ->form([
                    Select::make('location_id')->label('Location')->required()
                        ->options(Location::query()->where('is_sellable', true)->orderBy('name')->pluck('name', 'id')),
                    DatePicker::make('date')->label('Day')->required()->native(false)->minDate(now(CollectionSlots::ZONE)->startOfDay()),
                    Textarea::make('note')->label('Why (shown to staff)')->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $slots = new CollectionSlots;
                    $closed = $slots->close($slots->dayIds((int) $data['location_id'], (string) $data['date']), CollectionSlotResource::note($data));
                    Notification::make()->title($closed === 1 ? '1 slot closed' : "{$closed} slots closed")->success()->send();
                }),
        ];
    }

    public function getSubheading(): string
    {
        return 'When customers can collect, per location. Close a slot or a whole day; bookings already made stay until you move them.';
    }

    public function getTabs(): array
    {
        $groups = [];
        foreach (CollectionSlotResource::STATUSES as $status => $label) {
            $groups[$status] = [$label, [$status], CollectionSlotResource::statusColor($status)];
        }

        return StatusTabs::groups(CollectionSlot::class, $groups);
    }
}
