<?php

namespace App\Domain\Collection;

use App\Models\CollectionSlot;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CollectionSlots
{
    private const ZONE = 'Europe/London';

    public function __construct(private readonly CollectionSettings $settings = new CollectionSettings) {}

    public function generate(): int
    {
        $created = 0;
        $today = CarbonImmutable::now(self::ZONE)->startOfDay();

        foreach (Location::query()->where('is_sellable', true)->get(['id']) as $location) {
            $pattern = $this->settings->pattern($location->id);
            $horizon = $this->settings->integer('collection.booking_horizon_days', 14, $location->id);
            for ($day = 0; $day < $horizon; $day++) {
                $date = $today->addDays($day);
                foreach ($pattern[strtolower($date->format('D'))] ?? [] as $slot) {
                    if (! is_array($slot) || ! $this->validPatternSlot($slot)) {
                        continue;
                    }
                    $created += DB::table('collection_slots')->insertOrIgnore([
                        'location_id' => $location->id,
                        'slot_date' => $date->toDateString(),
                        'start_time' => $slot['start'],
                        'end_time' => $slot['end'],
                        'capacity' => $slot['capacity'],
                        'booked_count' => 0,
                        'status' => 'open',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        return $created;
    }

    public function offered(?int $locationId = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $query = CollectionSlot::query()->where('status', 'open')
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('slot_date', '>=', $now->setTimezone(self::ZONE)->toDateString())
            ->orderBy('slot_date')->orderBy('start_time');
        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return $query->get()->filter(fn (CollectionSlot $slot): bool => $this->isWithinWindow($slot, $now))->values()->all();
    }

    public function previewSlot(int $slotId): CollectionSlot
    {
        $slot = CollectionSlot::query()->where('id', $slotId)->first();
        if ($slot === null || $slot->status !== 'open' || $slot->booked_count >= $slot->capacity
            || ! $this->isWithinWindow($slot, CarbonImmutable::now('UTC'))) {
            throw new SlotUnavailable('This collection slot is no longer available.');
        }

        return $slot;
    }

    /** Must be called after the company lock and before any stock-level lock. */
    public function lockAvailable(int $slotId): CollectionSlot
    {
        $slot = CollectionSlot::query()->where('id', $slotId)->lockForUpdate()->first();
        if ($slot === null || $slot->status !== 'open' || $slot->booked_count >= $slot->capacity
            || ! $this->isWithinWindow($slot, CarbonImmutable::now('UTC'))) {
            throw new SlotUnavailable('This collection slot is no longer available.');
        }

        return $slot;
    }

    public function bookLocked(CollectionSlot $slot): void
    {
        $slot->booked_count++;
        $slot->status = $slot->booked_count >= $slot->capacity ? 'full' : 'open';
        $slot->save();
    }

    public function releaseLocked(CollectionSlot $slot): void
    {
        $slot->booked_count--;
        if ($slot->status === 'full') {
            $slot->status = 'open';
        }
        $slot->save();
    }

    public function endUtc(CollectionSlot $slot): CarbonImmutable
    {
        return CarbonImmutable::parse($slot->slot_date->format('Y-m-d').' '.$slot->end_time, self::ZONE)->utc();
    }

    public function startUtc(CollectionSlot $slot): CarbonImmutable
    {
        return CarbonImmutable::parse($slot->slot_date->format('Y-m-d').' '.$slot->start_time, self::ZONE)->utc();
    }

    private function isWithinWindow(CollectionSlot $slot, CarbonImmutable $now): bool
    {
        $horizon = $this->settings->integer('collection.booking_horizon_days', 14, $slot->location_id);
        $notice = $this->settings->integer('collection.min_notice_minutes', 120, $slot->location_id);
        $today = $now->setTimezone(self::ZONE)->startOfDay();

        return $this->startUtc($slot)->greaterThanOrEqualTo($now->addMinutes($notice))
            && $slot->slot_date->greaterThanOrEqualTo($today)
            && $slot->slot_date->lessThan($today->addDays($horizon));
    }

    private function validPatternSlot(array $slot): bool
    {
        return isset($slot['start'], $slot['end'], $slot['capacity'])
            && is_string($slot['start']) && is_string($slot['end'])
            && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $slot['start']) === 1
            && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $slot['end']) === 1
            && $slot['end'] > $slot['start'] && is_int($slot['capacity']) && $slot['capacity'] > 0;
    }
}
