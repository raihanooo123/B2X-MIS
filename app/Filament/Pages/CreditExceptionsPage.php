<?php

namespace App\Filament\Pages;

use App\Domain\Collection\SlotUnavailable;
use App\Domain\Credit\ApprovalKind;
use App\Domain\Credit\ApprovalStatus;
use App\Domain\Credit\CreditGate;
use App\Domain\Credit\CreditRefused;
use App\Domain\Credit\TradeApprovals;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\NoEligibleBatchException;
use App\Filament\Resources\CompanyResource;
use App\Filament\Support\MoneyFormatter;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\OrderApprovalRequest;
use App\Models\User;
use App\Support\DisplayTime;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 05.2 §18.3 — Credit control: the credit-shortfall queue (orders above
 * available credit, nothing reserved) with approve/reject, and the
 * overdue accounts. Accounts/admin (CompanyPolicy::manageAnyCredit).
 * Approving funds the order — stock, collection place and hold — only if
 * the limit now covers it; raise it on the company's credit page first.
 */
class CreditExceptionsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Credit control';

    protected static ?string $title = 'Credit control';

    protected static ?string $slug = 'credit-exceptions';

    protected static string $view = 'filament.pages.credit-exceptions';

    public static function canAccess(): bool
    {
        return Gate::allows('manageAnyCredit', Company::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Orders above available credit')
            ->description('Nothing is reserved for these orders. Raise the credit limit, then approve; or reject so the buyer can pay in advance.')
            ->query(OrderApprovalRequest::query()->with(['company', 'order', 'buyer'])
                ->where('approval_kind', ApprovalKind::CreditException->value)->where('status', ApprovalStatus::Pending->value))
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('order.order_number')->label('Order')->searchable(),
                TextColumn::make('company.name')->label('Account')->searchable(),
                TextColumn::make('buyer.email')->label('Buyer'),
                TextColumn::make('order_gross_minor')->label('Order total')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => (string) MoneyFormatter::minor($state)),
                TextColumn::make('available')->label('Available credit')->alignEnd()
                    ->state(fn (OrderApprovalRequest $r): string => $r->company === null ? '—' : (string) MoneyFormatter::minor((new CreditGate)->available($r->company))),
                TextColumn::make('expires_at')->label('Expires (UK)')->sortable()
                    ->formatStateUsing(fn ($state): string => DisplayTime::format($state, 'd/m/Y H:i')),
            ])
            ->actions([
                Action::make('credit')->label('Credit page')->icon('heroicon-o-banknotes')
                    ->url(fn (OrderApprovalRequest $r): string => CompanyResource::getUrl('credit', ['record' => $r->company_id])),
                Action::make('approve')->label('Approve')->color('success')->icon('heroicon-o-check')
                    ->requiresConfirmation()
                    ->modalHeading(fn (OrderApprovalRequest $r): string => 'Approve '.($r->order->order_number ?? 'order').'?')
                    ->modalDescription(fn (OrderApprovalRequest $r): string => ($r->company->name ?? '').': reserve stock and hold '
                        .MoneyFormatter::minor($r->order_gross_minor).' of credit. This does not approve on behalf of a company approver.')
                    ->modalSubmitActionLabel('Approve and reserve')
                    ->action(fn (OrderApprovalRequest $r) => $this->decide($r, true, null)),
                Action::make('reject')->label('Reject')->color('danger')->icon('heroicon-o-x-mark')
                    ->form([Textarea::make('reason')->label('Reason (shown to the buyer)')->required()->maxLength(500)])
                    ->modalSubmitActionLabel('Reject and cancel the order')
                    ->action(fn (OrderApprovalRequest $r, array $data) => $this->decide($r, false, (string) $data['reason'])),
            ])
            ->emptyStateHeading('No orders are waiting for a credit decision');
    }

    /**
     * Companies with debt past due, most overdue first.
     *
     * @return list<array{id: int, name: string, status: string, amount: string, oldest: string}>
     */
    public function overdueAccounts(): array
    {
        return array_values(CreditGate::outstanding(Invoice::query())->whereNotNull('company_id')->where('due_at', '<', now())
            ->toBase()->selectRaw('company_id, sum(total_gross_minor - paid_minor - credited_minor) AS amount, min(due_at) AS oldest')
            ->groupBy('company_id')->orderBy('oldest')->limit(100)->get()
            ->map(function (object $row): array {
                $company = Company::query()->find((int) $row->company_id, ['id', 'name', 'status']);

                return [
                    'id' => (int) $row->company_id,
                    'name' => $company->name ?? '',
                    'status' => $company->status ?? '',
                    'amount' => (string) MoneyFormatter::minor((int) $row->amount),
                    'oldest' => DisplayTime::format(Carbon::parse((string) $row->oldest), 'd/m/Y'),
                ];
            })->all());
    }

    private function decide(OrderApprovalRequest $request, bool $approve, ?string $reason): void
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return;
        }

        try {
            (new TradeApprovals)->decide($request->id, $actor, $approve, $reason);
            Notification::make()->title($approve ? 'Approved — stock and credit reserved' : 'Rejected — the order is cancelled')->success()->send();
        } catch (CreditRefused $e) {
            Notification::make()->title('Not decided')->body($e->getMessage())->danger()->send();
        } catch (InsufficientStockException|NoEligibleBatchException) {
            Notification::make()->title('Not enough stock')->body('There is no longer enough stock for this order. Reject it so the buyer can order again.')->danger()->send();
        } catch (SlotUnavailable $e) {
            Notification::make()->title('Collection slot unavailable')->body($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title('Not permitted')->danger()->send();
        } catch (ValidationException $e) {
            Notification::make()->title('Not decided')->body(implode(' ', $e->validator->errors()->all()))->danger()->send();
        }
    }
}
