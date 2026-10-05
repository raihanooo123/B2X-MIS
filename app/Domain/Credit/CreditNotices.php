<?php
namespace App\Domain\Credit;
use App\Domain\Notifications\NotificationDispatcher;
use App\Domain\Notifications\Recipient;
use App\Domain\Notifications\RecipientResolver;
use App\Models\CompanyUser;
use App\Models\OrderApprovalRequest;
use App\Models\User;
final class CreditNotices
{
    public function pending(OrderApprovalRequest $request, bool $reminder = false): void
    {
        if ($request->approval_kind === 'credit_exception') { $recipients = (new RecipientResolver)->role('accounts'); }
        else {
            $ids = CompanyUser::query()->where('company_id',$request->company_id)->whereIn('role',['owner','approver'])->where('user_id','<>',$request->requested_by_user_id)->pluck('user_id');
            $recipients = User::query()->whereIn('id',$ids)->where('status','active')->get()->map(fn (User $u) => Recipient::user($u,$request->company_id))->all();
        }
        (new NotificationDispatcher)->send(new CreditApprovalNotice($request->id,$reminder ? 'reminder' : 'pending', $reminder ? now()->format('Y-m-d') : ''),$recipients);
    }
    public function decided(OrderApprovalRequest $request): void
    {
        $user = User::query()->find($request->requested_by_user_id);
        if ($user !== null && $user->status === 'active') {
            (new NotificationDispatcher)->send(new CreditApprovalNotice($request->id,$request->status),[Recipient::user($user,$request->company_id)]);
        }
    }
    public function reminders(): void
    {
        OrderApprovalRequest::query()->where('status','pending')->where('expires_at','>',now())->orderBy('id')->eachById(fn (OrderApprovalRequest $a) => $this->pending($a,true));
    }
}
