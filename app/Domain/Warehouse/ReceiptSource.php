<?php

namespace App\Domain\Warehouse;

/**
 * `goods_receipts.source` (02 §23.1): the three goods-in sources this
 * module receives (05.5 §4.1). Returns and transfers have their own
 * inbound flows.
 */
enum ReceiptSource: string
{
    case PurchaseOrder = 'purchase_order';
    case Container = 'container';
    case Manual = 'manual';
}
