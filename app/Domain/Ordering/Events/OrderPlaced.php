<?php

namespace App\Domain\Ordering\Events;

/**
 * Dispatched via `DB::afterCommit()` once, at the end of
 * CheckoutService::checkout() — CLAUDE.md invariant 6 / doc 04 §4.4:
 * "no external calls inside the transaction... side effects are queued
 * jobs dispatched after commit." Confirmation email, warehouse
 * notification, search reindex (04 §4.4's own examples) all belong as
 * listeners on this event, none of them built here — this task is the
 * hook they attach to, not the notifications themselves.
 */
final class OrderPlaced
{
    public function __construct(public readonly int $orderId) {}
}
