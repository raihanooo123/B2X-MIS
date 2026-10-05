<?php

namespace App\Domain\Credit;

enum ApprovalKind: string
{
    case BuyerLimit = 'buyer_limit';
    case CreditException = 'credit_exception';
}
