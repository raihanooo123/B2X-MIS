<?php

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * The gateway could not be reached or refused the request itself — not a
 * card decline, which is an intent state (CardIntent::$declineCode).
 */
final class PaymentGatewayException extends RuntimeException {}
