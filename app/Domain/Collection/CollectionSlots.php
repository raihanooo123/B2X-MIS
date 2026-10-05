<?php

namespace App\Domain\Collection;

use App\Models\CollectionSlot;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 05.6 §7A.1 — collection slots are rows, always.
 *
 *   - generate(): the daily `collection:generate-slots` job. Creates each
 *     sellable location's slots from its weekly pattern (UK local times) up
 *     to the booking horizon. Idempotent on `collection_slots_slot_uq`; an
 *     existing slot, booked or not, is never changed or deleted, so a
 *     pattern change affects only days not yet generated.
 *   - offered(): open slots with room, at least the minimum notice ahead,
 *     within the horizon (`collection_slots_available_idx`).
 *   - lockAvailable(): checkout's step 2 of the global lock order
 *     (`companies` → `collection_slots` → `stock_levels`, invariant 6). A
 *     slot that doesn't exist, is closed, is full or is no longer offered
 *     is refused (422 `slot_unavailable`), never created on the fly.
 *
 * A slot's start and end instants are its UK local date and time converted
 * to UTC here, once, so DST is handled in one place (§7A.1).
 */
final class CollectionSlots
{
    public const ZONE = 'Europe/London';

    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(private readonly CollectionSettings $settings = new CollectionSettings) {}

    /** @return int slots created */
    public function generate(?CarbonImmutable $now = null): int
    {
        $created = 0;
        $today = ($now ?? CarbonImmutable::now())->setTimezone(self::ZONE)->startOfDay();

        foreach (Location::query()->where('is_sellable', true)->orderBy('id')->pluck('id') as $locationId) {
            $locationId = (int) $locationId;
            $pattern = $this->settings->pattern($locationId);
            if ($pattern === []) {
                continue;
            }
            $horizon = $this->settings->horizonDays($locationId);
            for ($day = 0; $day < $horizon; $day++) {
                // Calendar days in UK time: addDays keeps the date, whatever the offset.
                $date = $today->addDays($day);
                $entries = $pattern[self::DAYS[$date->dayOfWeekIso - 1]] ?? [];
                foreach (is_array($entries) ? $entries : [] as $entry) {
                    if (! is_array($entry) || ! self::validPatternEntry($entry)) {
                        continue;
                    }
                    $created += DB::table('collection_slots')->insertOrIgnore([
                        'location_id' => $locationId,
                        'slot_date' => $date->toDateString(),
                        'start_time' => $entry['start'],
                        'end_time' => $entry['end'],
                        'capacity' => $entry['capacity'],
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

    /**
     * @return list<CollectionSlot>
     */
    public function offered(?int $locationId = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone(self::ZONE)->toDateString();

        $slots = CollectionSlot::query()
            ->with('location:id,name')
            ->where('status', 'open')
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('slot_date', '>=', $today)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->orderBy('location_id')->orderBy('slot_date')->orderBy('start_time')
            ->get();

        $windows = [];

        return array_values($slots->filter(function (CollectionSlot $slot) use ($now, &$windows): bool {
            $windows[$slot->location_id] ??= [$this->settings->horizonDays($slot->location_id), $this->settings->minNoticeMinutes($slot->location_id)];

            return $this->isWithinWindow($slot, $now, ...$windows[$slot->location_id]);
        })->all());
    }

    /** Before any lock: the slot checkout would book, or why not. */
    public function previewSlot(int $slotId, ?CarbonImmutable $now = null): CollectionSlot
    {
        return $this->assertAvailable(CollectionSlot::query()->where('id', $slotId)->first(), $now);
    }

    /** Step 2 of the global lock order (invariant 6): after `companies`, before `stock_levels`. */
    public function lockAvailable(int $slotId, ?CarbonImmutable $now = null): CollectionSlot
    {
        return $this->assertAvailable(CollectionSlot::query()->where('id', $slotId)->lockForUpdate()->first(), $now);
    }

    /** The slot row is locked by the caller. `collection_slots_capacity_chk` is the backstop. */
    public function bookLocked(CollectionSlot $slot): void
    {
        $slot->booked_count++;
        if ($slot->status === 'open' && $slot->booked_count >= $slot->capacity) {
            $slot->status = 'full';
        }
        $slot->save();
    }

    /** The slot row is locked by the caller. A closed slot stays closed. */
    public function releaseLocked(CollectionSlot $slot): void
    {
        $slot->booked_count = max(0, $slot->booked_count - 1);
        if ($slot->status === 'full') {
            $slot->status = 'open';
        }
        $slot->save();
    }

    /**
     * §7A.1 closures: staff close a slot, or every slot of a day at a
     * location, in Filament. Locked ascending by id, as checkout locks one.
     * Its bookings stay on it until staff move them (§7A.7); none is orphaned.
     *
     * @param  list<int>  $slotIds
     * @return int slots closed
     */
    public function close(array $slotIds, ?string $note = null): int
    {
        sort($slotIds);

        return DB::transaction(function () use ($slotIds, $note): int {
            $closed = 0;
            foreach (CollectionSlot::query()->whereIn('id', $slotIds)->orderBy('id')->lockForUpdate()->get() as $slot) {
                if ($slot->status === 'closed') {
                    continue;
                }
                $slot->forceFill(['status' => 'closed', 'note' => $note ?? $slot->note])->save();
                $closed++;
            }

            return $closed;
        });
    }

    /** @return list<int> the slot ids of one UK day at a location */
    public function dayIds(int $locationId, string $date): array
    {
        return array_values(array_map('intval', CollectionSlot::query()->where('location_id', $locationId)->where('slot_date', $date)
            ->orderBy('id')->pluck('id')->all()));
    }

    /** A closed slot reopens as `open`, or `full` if its bookings fill it. */
    public function reopen(int $slotId): void
    {
        DB::transaction(function () use ($slotId): void {
            $slot = CollectionSlot::query()->where('id', $slotId)->lockForUpdate()->firstOrFail();
            if ($slot->status === 'closed') {
                $slot->forceFill(['status' => $slot->booked_count >= $slot->capacity ? 'full' : 'open'])->save();
            }
        });
    }

    public function startUtc(CollectionSlot $slot): CarbonImmutable
    {
        return CarbonImmutable::parse($slot->slot_date->format('Y-m-d').' '.$slot->start_time, self::ZONE)->utc();
    }

    public function endUtc(CollectionSlot $slot): CarbonImmutable
    {
        return CarbonImmutable::parse($slot->slot_date->format('Y-m-d').' '.$slot->end_time, self::ZONE)->utc();
    }

    /** §7A.5: slot end + grace, snapshotted onto the booking at placement. */
    public function paymentDueBy(CollectionSlot $slot): CarbonImmutable
    {
        return $this->endUtc($slot)->addMinutes($this->settings->graceMinutes($slot->location_id));
    }

    /** "Saturday 10 October, 09:00–12:00", UK time. */
    public static function label(CollectionSlot $slot): string
    {
        return $slot->slot_date->format('l j F').', '.substr($slot->start_time, 0, 5).'–'.substr($slot->end_time, 0, 5);
    }

    private function assertAvailable(?CollectionSlot $slot, ?CarbonImmutable $now): CollectionSlot
    {
        $now ??= CarbonImmutable::now();
        if ($slot === null || $slot->status !== 'open' || $slot->booked_count >= $slot->capacity
            || ! $this->isWithinWindow($slot, $now, $this->settings->horizonDays($slot->location_id), $this->settings->minNoticeMinutes($slot->location_id))) {
            throw new SlotUnavailable('This collection slot is no longer available. Please choose another.');
        }

        return $slot;
    }

    private function isWithinWindow(CollectionSlot $slot, CarbonImmutable $now, int $horizonDays, int $noticeMinutes): bool
    {
        $today = $now->setTimezone(self::ZONE)->startOfDay();
        $date = CarbonImmutable::parse($slot->slot_date->format('Y-m-d'), self::ZONE);

        return $this->startUtc($slot)->greaterThanOrEqualTo($now->addMinutes($noticeMinutes))
            && $date->lessThan($today->addDays($horizonDays));
    }

    /**
     * @param  array<mixed>  $entry
     */
    private static function validPatternEntry(array $entry): bool
    {
        $time = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

        return isset($entry['start'], $entry['end'], $entry['capacity'])
            && is_string($entry['start']) && is_string($entry['end'])
            && preg_match($time, $entry['start']) === 1 && preg_match($time, $entry['end']) === 1
            && $entry['end'] > $entry['start']
            && is_int($entry['capacity']) && $entry['capacity'] > 0 && $entry['capacity'] <= 32767;
    }
}
