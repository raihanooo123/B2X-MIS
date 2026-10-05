<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Domain\Billing\PaymentTerms;
use App\Domain\Credit\CreditControl;
use App\Domain\Credit\CreditOverview;
use App\Domain\Credit\CreditPayouts;
use App\Domain\Credit\CreditRefused;
use App\Domain\Credit\CreditSettings;
use App\Domain\Identity\CompanyMemberSettings;
use App\Filament\Resources\CompanyResource;
use App\Filament\Support\MoneyFormatter;
use App\Models\AccountCreditPayout;
use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 05.2 §18.3 — /admin/companies/{id}/credit: one company's credit for
 * accounts/admin — summary, a reasoned limit/terms/suspend/reinstate
 * form, and balance payouts (request, then a second person approves).
 * Every change goes through CreditControl / CreditPayouts, which
 * re-authorise under the company lock and audit with the reason.
 *
 * @property Form $form
 */
class CompanyCredit extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = CompanyResource::class;

    protected static string $view = 'filament.resources.company-resource.pages.company-credit';

    protected static ?string $title = 'Credit';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        return Gate::allows('manageAnyCredit', Company::class);
    }

    public function getTitle(): string
    {
        return 'Credit — '.$this->company()->name;
    }

    public function company(): Company
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Company, 404);

        return $record;
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->fillForm();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->columns(2)->schema([
            TextInput::make('credit_limit')->label('Credit limit (£)')->required()->regex('/^\d{1,9}(\.\d{1,2})?$/')
                ->helperText('In pounds, e.g. 5000 or 5000.50.'),
            Select::make('payment_terms')->label('Payment terms')->required()
                ->options(collect(PaymentTerms::cases())->mapWithKeys(fn (PaymentTerms $t): array => [$t->value => $t->value])->all()),
            Select::make('status')->label('Account')->required()->live()
                ->options(['approved' => 'Active', 'suspended' => 'Suspended']),
            Select::make('suspension_reason')->label('Suspension reason')
                ->options(['debt' => 'Debt — prepayment still allowed', 'fraud' => 'Fraud — no sales', 'legal' => 'Legal — no sales', 'manual' => 'Other — no sales'])
                ->visible(fn (Get $get): bool => $get('status') === 'suspended')->required(fn (Get $get): bool => $get('status') === 'suspended'),
            Textarea::make('reason')->label('Reason for this change')->required()->maxLength(500)->columnSpanFull(),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return;
        }

        try {
            (new CreditControl)->update(
                $this->company()->id, $actor, CompanyMemberSettings::poundsToMinor((string) $data['credit_limit']),
                (string) $data['payment_terms'], (string) $data['status'], (string) $data['reason'],
                isset($data['suspension_reason']) ? (string) $data['suspension_reason'] : null,
            );
            $this->company()->refresh();
            $this->fillForm();
            Notification::make()->title('Credit updated')->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title('Not saved')->body(implode(' ', $e->validator->errors()->all()))->danger()->send();
        } catch (CreditRefused $e) {
            Notification::make()->title('Not saved')->body($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title('Not permitted')->danger()->send();
        }
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return (new CreditOverview)->summary($this->company()->refresh());
    }

    /** @return list<array<string, mixed>> */
    public function payouts(): array
    {
        return array_values(AccountCreditPayout::query()->with(['requestedBy:id,first_name,last_name', 'approvedBy:id,first_name,last_name'])
            ->where('company_id', $this->company()->id)->orderByDesc('requested_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn (AccountCreditPayout $p): array => [
                'id' => $p->id,
                'amount' => (string) MoneyFormatter::minor($p->amount_minor),
                'status' => $p->status,
                'requested_by' => trim(($p->requestedBy->first_name ?? '').' '.($p->requestedBy->last_name ?? '')),
                'approved_by' => $p->approvedBy === null ? null : trim($p->approvedBy->first_name.' '.$p->approvedBy->last_name),
                'requested_at' => $p->requested_at,
                'can_approve' => $p->status === 'pending' && $p->requested_by_user_id !== Auth::id(),
            ])->all());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestPayout')->label('Pay out balance')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => $this->company()->account_balance_minor > 0)
                ->modalDescription(fn (): string => 'Spendable balance: '.MoneyFormatter::minor($this->company()->account_balance_minor)
                    .'. Refunded to the original card; another accounts or admin person must approve it.')
                ->form([
                    Select::make('source_payment_id')->label('Original card payment')->required()
                        ->options(fn (): array => Payment::query()->where('company_id', $this->company()->id)->where('type', 'payment')
                            ->where('gateway', 'stripe')->whereIn('status', ['captured', 'part_refunded'])->orderByDesc('id')->limit(50)->get()
                            ->mapWithKeys(fn (Payment $p): array => [$p->public_id => MoneyFormatter::minor($p->amount_minor).' — '.($p->card_brand ?? 'card').' '.($p->card_last4 ?? '').' — '.$p->public_id])
                            ->all()),
                    TextInput::make('amount')->label('Amount (£)')->required()->regex('/^\d{1,9}(\.\d{1,2})?$/'),
                    Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                ])
                ->modalSubmitActionLabel('Request payout')
                ->action(function (array $data): void {
                    $this->guarded(fn (User $actor) => (new CreditPayouts)->request($this->company()->id, $actor, 'original_card',
                        CompanyMemberSettings::poundsToMinor((string) $data['amount']), (string) $data['source_payment_id'], (string) $data['reason']), 'Payout requested — awaiting a second approver');
                }),
            Action::make('view')->label('Company')->color('gray')
                ->url(fn (): string => CompanyResource::getUrl('view', ['record' => $this->company()])),
        ];
    }

    public function approvePayoutAction(): Action
    {
        return Action::make('approvePayout')->label('Approve')->color('success')->requiresConfirmation()
            ->modalDescription('Refund this amount to the original card now. This cannot be undone once the card refund succeeds.')
            ->modalSubmitActionLabel('Approve and refund')
            ->action(function (array $arguments): void {
                $payouts = new CreditPayouts;
                $this->guarded(function (User $actor) use ($payouts, $arguments): void {
                    $payouts->approve((int) $arguments['payout'], $actor);
                    // After commit (CLAUDE.md invariant 6).
                    $payouts->settle((int) $arguments['payout']);
                }, 'Payout approved');
            });
    }

    public function rejectPayoutAction(): Action
    {
        return Action::make('rejectPayout')->label('Reject')->color('danger')
            ->form([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
            ->modalSubmitActionLabel('Reject and return to balance')
            ->action(function (array $arguments, array $data): void {
                $this->guarded(fn (User $actor) => (new CreditPayouts)->reject((int) $arguments['payout'], $actor, (string) $data['reason']), 'Payout rejected — the balance is restored');
            });
    }

    /** @param callable(User): mixed $operation */
    private function guarded(callable $operation, string $success): void
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return;
        }

        try {
            $operation($actor);
            $this->company()->refresh();
            Notification::make()->title($success)->success()->send();
        } catch (CreditRefused $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->send();
        } catch (ValidationException $e) {
            Notification::make()->title('Not done')->body(implode(' ', $e->validator->errors()->all()))->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title('Not permitted')->danger()->send();
        }
    }

    private function fillForm(): void
    {
        $this->form->fill([
            'credit_limit' => CompanyMemberSettings::minorToPounds($this->company()->credit_limit_minor),
            'payment_terms' => $this->company()->payment_terms,
            'status' => $this->company()->status,
            'suspension_reason' => $this->company()->status === 'suspended' ? (new CreditSettings)->suspensionReason($this->company()->id) : null,
            'reason' => null,
        ]);
    }
}
