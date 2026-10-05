<?php

namespace App\Domain\Credit;

use App\Domain\Ordering\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 05.2 §18.1 — hourly: rebuild each company's credit projections from
 * their sources and flag any drift (07 P1). Never repairs: a difference
 * means a writer broke the rules, and history is what accounts trust.
 *
 *   credit_held_minor      = Σ live (`held`) credit_holds
 *   credit_used_minor      = Σ outstanding on-account trade invoices
 *   account_balance_minor  = Σ account_credit_movements
 */
final class CreditReconciliation
{
    /** @return list<array{company_id: int, field: string, stored: int, rebuilt: int}> */
    public function drift(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.id,
                   c.credit_held_minor, c.credit_used_minor, c.account_balance_minor,
                   coalesce((SELECT sum(h.amount_minor) FROM credit_holds h WHERE h.company_id = c.id AND h.status = 'held'), 0) AS held,
                   coalesce((SELECT sum(i.total_gross_minor - i.paid_minor - i.credited_minor)
                               FROM invoices i JOIN orders o ON o.id = i.order_id
                              WHERE i.company_id = c.id AND i.status IN ('issued','part_paid','overdue')
                                AND o.payment_method = ?), 0) AS used,
                   coalesce((SELECT sum(m.amount_minor) FROM account_credit_movements m WHERE m.company_id = c.id), 0) AS balance
              FROM companies c
             ORDER BY c.id
            SQL, [PaymentMethod::OnAccount->value]);

        $drift = [];
        foreach ($rows as $row) {
            foreach (['credit_held_minor' => 'held', 'credit_used_minor' => 'used', 'account_balance_minor' => 'balance'] as $field => $rebuilt) {
                if ((int) $row->{$field} !== (int) $row->{$rebuilt}) {
                    $drift[] = ['company_id' => (int) $row->id, 'field' => $field, 'stored' => (int) $row->{$field}, 'rebuilt' => (int) $row->{$rebuilt}];
                }
            }
        }

        foreach ($drift as $difference) {
            Log::critical('Credit projection drift — investigate; nothing was repaired (05.2 §18.1).', $difference);
        }

        return $drift;
    }
}
