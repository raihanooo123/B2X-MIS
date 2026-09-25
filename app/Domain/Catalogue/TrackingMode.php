<?php

namespace App\Domain\Catalogue;

/**
 * `skus.tracking_mode` (02 §5.5), mirrored here as the single source for
 * its CHECK list. Decides what goods-in captures (05.5 §4.3): a batch code
 * (and expiry where `requires_expiry`), one serial per base unit, both, or
 * neither.
 */
enum TrackingMode: string
{
    case None = 'none';
    case Batch = 'batch';
    case Serial = 'serial';
    case BatchAndSerial = 'batch_and_serial';

    public function tracksBatch(): bool
    {
        return $this === self::Batch || $this === self::BatchAndSerial;
    }

    public function tracksSerial(): bool
    {
        return $this === self::Serial || $this === self::BatchAndSerial;
    }
}
