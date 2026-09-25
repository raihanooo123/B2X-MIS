<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Staff access to invoices and receipts. Read-only for everyone: a
 * document is issued only by InvoiceService, and corrected by credit note
 * (02 §14.5.3), never edited. Viewing includes downloading the archived
 * PDF.
 */
final class InvoicePolicy
{
    use DeniesDeletion;

    /** @var list<string> */
    public const VIEWERS = ['admin', 'accounts', 'sales_manager'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::VIEWERS);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
