<?php

namespace FLAIRUK\Starlink\Events;

use FLAIRUK\Starlink\Telemetry\Record;

/**
 * An alert became active on a device: it was not active in the device's previous row.
 */
class AlertRaised
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
