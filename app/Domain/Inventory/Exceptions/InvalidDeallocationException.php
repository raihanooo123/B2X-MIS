<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested release cannot be applied: the allocation is
 * already dispatched/released (nothing left to reverse), or reversing it
 * would drive stock_levels.allocated_base_qty negative (a projection
 * that should never happen if allocate() and deallocate() are the only
 * writers, but stock_levels_allocated_chk's own CHECK — belt and braces
 * — is the last line of defence, and this exception is the first).
 */
final class InvalidDeallocationException extends RuntimeException {}
