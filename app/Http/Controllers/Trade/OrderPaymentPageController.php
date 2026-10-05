<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Credit\ApprovalKind;
use App\Domain\Credit\ApprovalStatus;
use App\Domain\Credit\ApprovedOrderPayment;
use App\Domain\Credit\CreditRefused;
use App\Http\Controllers\Controller;
use App\Http\Support\TradeContext;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /trade/orders/{order}/pay (05.2 §18.1): the buyer pays an approved
 * prepaid trade order by card within the payment window. Read only —
 * POST /api/v1/orders/{id}/card-intent and /pay take the payment.
 */
class OrderPaymentPageController extends Controller
{
    public function __invoke(Request $request, string $order, ApprovedOrderPayment $payments): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        $record = Order::query()->where('public_id', $order)->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();

        $refusal = null;
        try {
            $payments->assertPayable($record, $user);
        } catch (CreditRefused $e) {
            $refusal = ['code' => $e->reason, 'message' => $e->getMessage()];
        }

        return Inertia::render('Trade/Orders/Pay', [
            'order' => [
                'id' => $record->public_id,
                'order_number' => $record->order_number,
                'status' => $record->status,
                'total_gross_minor' => $record->total_gross_minor,
                'pay_by' => $record->status === 'pending_payment' ? $payments->deadline($record)->toIso8601ZuluString() : null,
                'confirmation_url' => route('orders.confirmation', $record->public_id),
            ],
            'refusal' => $refusal,
            // 05.2 §8.1 row 3: a credit shortfall can be paid by card instead of waiting for accounts.
            'can_pay_in_advance' => $record->status === 'awaiting_approval' && OrderApprovalRequest::query()->where('order_id', $record->id)
                ->where('approval_kind', ApprovalKind::CreditException->value)->where('status', ApprovalStatus::Pending->value)->exists(),
            // 07 §6.4: the publishable key only.
            'stripe_key' => (string) config('services.stripe.secret') !== '' ? (string) config('services.stripe.key') : null,
        ]);
    }
}
