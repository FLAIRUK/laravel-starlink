<?php

namespace FLAIRUK\Starlink\Events;

use FLAIRUK\Starlink\Telemetry\Batch;

/**
 * A batch with at least one row arrived from the stream.
 *
 * Listeners run before the next batch is asked for. The stream has already
 * moved past these rows, so a listener that fails loses them: store the rows
 * first, then do anything slow on a queue.
 */
class TelemetryReceived
{
    public function __construct(
        public readonly string $account,
        public readonly Batch $batch,
    ) {}
}
