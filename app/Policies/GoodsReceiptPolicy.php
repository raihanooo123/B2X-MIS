<?php

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Goods-in (05.5 §4). Warehouse operatives receive; purchasing can see
 * receipts — manual receipts are theirs to match (05.5 §12) — but books
 * nothing. A receipt is never deleted: its lines have moved stock.
 */
final class GoodsReceiptPolicy
{
    use DeniesDeletion;

    /** @var list<string> */
    public const RECEIVERS = ['admin', 'warehouse'];

    /** @var list<string> */
    public const VIEWERS = ['admin', 'warehouse', 'purchasing'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::VIEWERS);
    }

    public function view(User $user, GoodsReceipt $receipt): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::RECEIVERS);
    }

    /** Booking lines into, and closing, a receipt. */
    public function update(User $user, GoodsReceipt $receipt): bool
    {
        return $user->hasAnyRole(self::RECEIVERS);
    }
}
