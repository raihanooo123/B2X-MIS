<?php

namespace App\Domain\Credit;

use App\Domain\Notifications\Notices\OrderApprovalDecided;
use App\Domain\Notifications\Notices\OrderAwaitingApproval;
use App\Domain\Notifications\NotificationDispatcher;
use App\Domain\Notifications\Recipient;
use App\Domain\Notifications\RecipientResolver;
use App\Models\CompanyUser;
use App\Models\OrderApprovalRequest;
use App\Models\User;
use App\Support\DisplayTime;

/**
 * Who hears about an approval request (05.2 §10–§11, 05.12 §5). Sends are
 * queued after commit by NotificationDispatcher, so a rolled-back
 * decision sends nothing.
 */
final class CreditNotices
{
    public function pending(OrderApprovalRequest $request, bool $reminder = false): void
    {
        $notice = new OrderAwaitingApproval($request->id, $reminder, $reminder ? DisplayTime::local(now())->toDateString() : '');
        (new NotificationDispatcher)->send($notice, $this->deciders($request));
    }

    /** The buyer who placed it, when the request was approved or rejected. */
    public function decided(OrderApprovalRequest $request): void
    {
        if (! in_array($request->status, [ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value], true)) {
            return;
        }
        $buyer = User::query()->where('id', $request->requested_by_user_id)->where('status', 'active')->first();
        if ($buyer !== null) {
            (new NotificationDispatcher)->send(
                new OrderApprovalDecided($request->id, $request->status === ApprovalStatus::Approved->value),
                [Recipient::user($buyer, $request->company_id)],
            );
        }
    }

    /** Daily, while still pending (05.2 §10). */
    public function reminders(): int
    {
        $sent = 0;
        OrderApprovalRequest::query()->where('status', ApprovalStatus::Pending->value)->where('expires_at', '>', now())
            ->orderBy('id')->each(function (OrderApprovalRequest $request) use (&$sent): void {
                $this->pending($request, reminder: true);
                $sent++;
            });

        return $sent;
    }

    /** @return list<Recipient> */
    private function deciders(OrderApprovalRequest $request): array
    {
        if ($request->approval_kind === ApprovalKind::CreditException->value) {
            return (new RecipientResolver)->role('accounts');
        }

        $ids = CompanyUser::query()->where('company_id', $request->company_id)
            ->whereIn('role', ['owner', 'approver'])->where('user_id', '<>', $request->requested_by_user_id)
            ->pluck('user_id');

        return array_values(User::query()->whereIn('id', $ids)->where('status', 'active')->orderBy('id')->get()
            ->map(fn (User $user): Recipient => Recipient::user($user, $request->company_id))->all());
    }
}
