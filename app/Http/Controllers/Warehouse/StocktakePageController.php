<?php

namespace App\Http\Controllers\Warehouse;

use App\Domain\Warehouse\StocktakeReason;
use App\Domain\Warehouse\StocktakeService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Warehouse\StocktakeResource;
use App\Models\Location;
use App\Models\Stocktake;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The stocktake screen (05.5 §8, §9) — `Warehouse/Stocktake`. Reads and
 * writes through `/api/v1/warehouse/stocktakes*`; these props are the
 * locations, the sessions in progress, the reason list, and with
 * `?stocktake=` the session to resume.
 */
class StocktakePageController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('viewAny', Stocktake::class);

        $id = $request->query('stocktake');
        $stocktake = is_string($id) ? Stocktake::query()->where('public_id', $id)->first() : null;

        $inProgress = Stocktake::query()
            ->whereIn('status', StocktakeService::COUNTING_STATUSES)
            ->withCount('lines')
            ->orderByDesc('started_at')
            ->get();
        $codes = Location::query()->whereIn('id', $inProgress->pluck('location_id'))->pluck('code', 'id');

        return Inertia::render('Warehouse/Stocktake', [
            'stocktake' => $stocktake === null ? null : (new StocktakeResource($stocktake))->resolve($request),
            'in_progress' => $inProgress->map(fn (Stocktake $s) => [
                'id' => $s->public_id,
                'location_code' => $codes->get($s->location_id),
                'status' => $s->status,
                'is_blind' => $s->is_blind,
                'started_at' => $s->started_at->toIso8601String(),
                'line_count' => (int) $s->getAttribute('lines_count'),
            ])->values()->all(),
            'locations' => Location::query()->orderBy('code')->get(['code', 'name'])->map(fn (Location $l) => ['code' => $l->code, 'name' => $l->name])->values()->all(),
            'reasons' => array_map(fn (StocktakeReason $r) => ['value' => $r->value, 'label' => $r->label()], StocktakeReason::cases()),
            'can_count' => Gate::allows('create', Stocktake::class),
        ]);
    }
}
