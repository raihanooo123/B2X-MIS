<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Warehouse\ReturnResource;
use App\Models\Attachment;
use App\Models\Rma;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 05.4 §7.3, §13.5 — the staff Returns screen: returns waiting for their
 * goods, and finding one by its RMA number to book it in or review its
 * proof of sending. Reads and writes via /api/v1/warehouse/returns*.
 */
class ReturnsPageController extends Controller
{
    private const EXPECTED_SHOWN = 50;

    public function show(Request $request): Response
    {
        Gate::authorize('viewAny', Rma::class);

        $number = $request->query('rma');
        $rma = is_string($number) ? Rma::query()->where('rma_number', strtoupper(trim($number)))->first() : null;

        return Inertia::render('Warehouse/Returns', [
            'selected' => $rma === null ? null : (new ReturnResource($rma))->resolve($request),
            'expected' => Rma::query()
                ->where('status', 'awaiting_goods')
                ->orderBy('return_by_date')
                ->orderBy('id')
                ->limit(self::EXPECTED_SHOWN)
                ->get()
                ->map(fn (Rma $r) => [
                    'rma_number' => $r->rma_number,
                    'return_by_date' => $r->return_by_date?->toDateString(),
                    'has_proof' => $r->goods_sent_at !== null,
                    'refund_due_on' => $r->refund_due_on?->toDateString(),
                    'we_collect' => $r->return_method === 'collection',
                ])->values()->all(),
            // 05.4 §13.4: problem reports waiting for a handler, and returns to inspect or settle.
            'to_review' => Rma::query()->where('status', 'requested')->orderBy('requested_at')->limit(self::EXPECTED_SHOWN)->get()
                ->map(fn (Rma $r) => ['rma_number' => $r->rma_number, 'reason' => $r->return_reason, 'requested_at' => $r->requested_at->toIso8601ZuluString()])->values()->all(),
            'to_settle' => Rma::query()->whereIn('status', ['received', 'inspected'])->orderBy('refund_due_on')->orderBy('id')->limit(self::EXPECTED_SHOWN)->get()
                ->map(fn (Rma $r) => ['rma_number' => $r->rma_number, 'status' => $r->status, 'refund_due_on' => $r->refund_due_on?->toDateString()])->values()->all(),
            'can_record_cancellation' => Gate::allows('recordCancellation', Rma::class),
        ]);
    }

    /** A customer's proof of sending, for staff to review. Never public. */
    public function proof(string $rma, string $attachment): StreamedResponse
    {
        $model = Rma::query()->where('public_id', $rma)->firstOrFail();
        Gate::authorize('view', $model);

        $file = Attachment::query()
            ->where('attachable_type', 'rma')
            ->where('attachable_id', $model->id)
            ->where('public_id', $attachment)
            ->firstOrFail();

        return Storage::disk($file->disk)->response($file->path, $file->original_name ?? 'proof', ['Content-Type' => $file->mime_type]);
    }
}
