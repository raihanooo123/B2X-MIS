<?php

namespace App\Domain\Credit;

enum CreditPayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
}
