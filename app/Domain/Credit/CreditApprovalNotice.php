<?php
namespace App\Domain\Credit;
use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Filament\Support\MoneyFormatter;
use App\Models\OrderApprovalRequest;
use App\Support\DisplayTime;
final class CreditApprovalNotice extends Notice
{
    public function __construct(public readonly int $requestId, public readonly string $state, public readonly string $day = '') {}
    public function key(): NotificationKey { return NotificationKey::CreditApproval; }
    public function subject(): array { return ['order_approval_request',$this->requestId]; }
    public function occurrence(): string { return $this->state.':'.$this->day; }
    public function content(Recipient $recipient): MailContent
    {
        $a = OrderApprovalRequest::query()->with('order')->findOrFail($this->requestId);
        $order = $a->order;
        return new MailContent(subject: 'Trade order approval: '.($order?->order_number ?? ''), heading: 'Order approval '.str_replace('_',' ',$this->state),
            paragraphs: ['The request expires 48 hours after submission. Approval does not bypass account credit or stock checks.'],
            facts: [['label'=>'Order','value'=>$order?->order_number ?? ''],['label'=>'Gross','value'=>MoneyFormatter::minor($a->order_gross_minor)],
                ['label'=>'Expires (UK time)','value'=>DisplayTime::format($a->expires_at,'d/m/Y H:i')]],
            actionLabel: 'Review order', actionUrl: url($a->approval_kind === 'credit_exception' && in_array($this->state,['pending','reminder'],true) ? '/admin/credit-approvals' : '/trade/approvals/'.$a->public_id));
    }
}
