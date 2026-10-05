<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Collection\CollectionSlots;
use App\Http\Controllers\Controller;
use App\Models\CollectionSlot;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/collection-slots — 05.6 §7A.1: the slots checkout offers.
 * Open, with room, at least the minimum notice ahead, within the horizon.
 * Guests too: collection is open to every customer (§7A.2). `collection_slots`
 * has no public id; checkout books by this id and re-checks it under lock.
 */
final class CollectionSlotController extends Controller
{
    public function index(CollectionSlots $slots): JsonResponse
    {
        return response()->json(['data' => array_map(fn (CollectionSlot $slot): array => [
            'id' => $slot->id,
            'location_name' => $slot->location?->name,
            'slot_date' => $slot->slot_date->toDateString(),
            'start_time' => substr($slot->start_time, 0, 5),
            'end_time' => substr($slot->end_time, 0, 5),
            'label' => CollectionSlots::label($slot),
        ], $slots->offered())]);
    }
}
