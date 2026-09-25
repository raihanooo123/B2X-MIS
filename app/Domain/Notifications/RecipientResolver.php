<?php

namespace App\Domain\Notifications;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;

/**
 * 05.12 §7.1 — who each kind of message goes to. Closed accounts receive
 * nothing; everyone else's current address is used.
 */
final class RecipientResolver
{
    /**
     * The customer user on the order — never the rep who placed it for them (05.8 §8).
     *
     * @return list<Recipient>
     */
    public function orderCustomer(Order $order): array
    {
        $user = $order->user_id === null ? null : $this->activeUser($order->user_id);

        return $user === null ? [] : [Recipient::user($user, $order->company_id)];
    }

    /**
     * Invoice messages (`invoice.*`, `payment.received`): the public
     * customer for a receipt; for a trade invoice, the company's
     * accounts-payable mailbox when set, otherwise its default contact
     * and owners.
     *
     * @return list<Recipient>
     */
    public function invoiceRecipients(Invoice $invoice): array
    {
        if ($invoice->company_id === null) {
            $order = Order::query()->find($invoice->order_id, ['id', 'user_id', 'company_id']);

            return $order === null ? [] : $this->orderCustomer($order);
        }

        $accountsEmail = Company::query()->whereKey($invoice->company_id)->value('accounts_email');
        if (is_string($accountsEmail) && trim($accountsEmail) !== '') {
            return [new Recipient($accountsEmail, null, $invoice->company_id)];
        }

        return $this->defaultContactAndOwners($invoice->company_id);
    }

    /** @return list<Recipient> */
    public function defaultContactAndOwners(int $companyId): array
    {
        $userIds = CompanyUser::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('is_default_contact', true)->orWhere('role', 'owner'))
            ->pluck('user_id');

        return $this->users($userIds->all(), $companyId);
    }

    /** @return list<Recipient> */
    public function companyOwners(int $companyId): array
    {
        $userIds = CompanyUser::query()->where('company_id', $companyId)->where('role', 'owner')->pluck('user_id');

        return $this->users($userIds->all(), $companyId);
    }

    /**
     * Every active staff user holding the role (02 §14.1).
     *
     * @return list<Recipient>
     */
    public function role(string $code): array
    {
        $userIds = User::query()
            ->whereHas('roles', fn ($q) => $q->where('code', $code))
            ->pluck('id');

        return $this->users($userIds->all(), null);
    }

    /** @return list<Recipient> */
    public function assignedRep(int $companyId): array
    {
        $repId = Company::query()->whereKey($companyId)->value('assigned_rep_user_id');

        return $repId === null ? [] : $this->users([(int) $repId], null);
    }

    /**
     * @param  array<int, mixed>  $userIds
     * @return list<Recipient>
     */
    private function users(array $userIds, ?int $companyId): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_values(User::query()
            ->whereKey($userIds)
            ->where('status', '<>', 'closed')
            ->orderBy('id')
            ->get(['id', 'email', 'first_name'])
            ->map(fn (User $user) => Recipient::user($user, $companyId))
            ->all());
    }

    private function activeUser(int $userId): ?User
    {
        return User::query()->whereKey($userId)->where('status', '<>', 'closed')->first(['id', 'email', 'first_name']);
    }
}
