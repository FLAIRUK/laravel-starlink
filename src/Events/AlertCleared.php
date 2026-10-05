<?php

namespace FLAIRUK\Starlink\Events;

use FLAIRUK\Starlink\Telemetry\Record;

/**
 * An alert stopped being active on a device: it was active in the device's previous row and is not in this one.
 */
class AlertCleared
{
    public function __construct(
        public readonly string $account,
        public readonly Record $record,
        public readonly int $code,
        public readonly ?string $name,
    ) {}

    public function deviceId(): ?string
    {
        return $this->record->deviceId();
    }
}
