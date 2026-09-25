<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Notifications\DeliveryEvents;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/v1/webhooks/postmark — delivery outcomes for our email (05.12
 * §10.2). Postmark authenticates webhooks with HTTP basic auth on the URL
 * (05.12 §6.1), checked in constant time before the body is read.
 *
 *   - Delivery      → `delivered`
 *   - Bounce, hard  → `bounced` (suppresses further mail, §10.4). Soft
 *                     bounces are Postmark's to retry and change nothing.
 *   - SpamComplaint → `complained`
 *
 * Every event is acknowledged with a 2xx once authenticated, so Postmark
 * stops retrying; unknown message ids and record types are ignored.
 */
class PostmarkWebhookController extends Controller
{
    /** Postmark bounce types that mean the address will not accept mail. */
    private const HARD_BOUNCES = ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'];

    public function __invoke(Request $request, DeliveryEvents $events): JsonResponse
    {
        $user = (string) config('services.postmark.webhook_user');
        $password = (string) config('services.postmark.webhook_password');
        if ($user === '' || $password === '') {
            Log::critical('Postmark webhook received but POSTMARK_WEBHOOK_USER/PASSWORD is not set.');

            return response()->json(['error' => 'not configured'], 503);
        }

        if (! hash_equals($user, (string) $request->getUser()) || ! hash_equals($password, (string) $request->getPassword())) {
            return response()->json(['error' => 'unauthorised'], 401);
        }

        $messageId = $request->input('MessageID');
        if (! is_string($messageId) || $messageId === '') {
            return response()->json(['status' => 'ignored']);
        }

        [$next, $at, $detail] = match ($request->input('RecordType')) {
            'Delivery' => [NotificationStatus::Delivered, $this->time($request->input('DeliveredAt')), null],
            'Bounce' => in_array($request->input('Type'), self::HARD_BOUNCES, true)
                ? [NotificationStatus::Bounced, null, 'bounce: '.$request->input('Type').' '.$request->input('Description', '')]
                : [null, null, null],
            'SpamComplaint' => [NotificationStatus::Complained, null, 'spam complaint'],
            default => [null, null, null],
        };

        if ($next !== null) {
            $events->apply(NotificationChannel::Email, $messageId, $next, $at, is_string($detail) ? trim($detail) : null);
        }

        return response()->json(['status' => 'ok']);
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
