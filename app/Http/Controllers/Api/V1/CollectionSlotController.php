<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Collection\CollectionSlots;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class CollectionSlotController extends Controller
{
    public function index(CollectionSlots $slots): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($slot): array => [
            'id' => $slot->id,
            'location_id' => $slot->location_id,
            'location_name' => $slot->location->name,
            'slot_date' => $slot->slot_date->toDateString(),
            'start_time' => substr($slot->start_time, 0, 5),
            'end_time' => substr($slot->end_time, 0, 5),
        ], array_map(fn ($slot) => $slot->load('location'), $slots->offered()))]);
    }
}
