<?php

namespace App\Domain\Reference\Exceptions;

use RuntimeException;

/**
 * The requested key_name has no row in number_sequences. Deliberately
 * not auto-created: a typo'd key (e.g. 'order_nubmer') silently starting
 * a phantom sequence at 1 is a worse failure than a loud exception for a
 * table whose whole purpose is being trustworthy enough for HMRC to
 * inspect (Doc 02 §11.3). Provisioning a series is an explicit setup
 * step, not something this service infers.
 */
final class UnknownNumberSequenceException extends RuntimeException
{
    public function __construct(public readonly string $keyName)
    {
        parent::__construct("No number_sequences row for key_name '{$keyName}' — provision it explicitly before issuing numbers against it.");
    }
}
