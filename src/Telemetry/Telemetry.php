<?php

namespace FLAIRUK\Starlink\Telemetry;

use FLAIRUK\Starlink\Client;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The telemetry endpoints for one Starlink account.
 *
 * Both need the service account to hold "Device telemetry, View".
 */
class Telemetry
{
    public function __construct(
        protected Client $client,
        protected string $account,
        protected Dispatcher $events,
        protected ?Repository $state = null,
        protected int $batchSize = 1000,
        protected int $maxLingerMs = 15000,
    ) {}

    /**
     * The next batch from the stream: up to `$batchSize` rows collected for
     * at most `$maxLingerMs`, for every device on the account.
     *
     * This moves the service account's read position past the rows it
     * returns. They are not sent again, so keep them before doing anything
     * that can fail. Rows are kept for 8 hours.
     */
    public function stream(?int $batchSize = null, ?int $maxLingerMs = null): Batch
    {
        $batchSize = max(1, min(65000, $batchSize ?? $this->batchSize));
        $maxLingerMs = max(0, min(65000, $maxLingerMs ?? $this->maxLingerMs));

        return Batch::fromResponse($this->client->post(
            'public/v2/telemetry/stream',
            ['batchSize' => $batchSize, 'maxLingerMs' => $maxLingerMs],
            (int) ceil($maxLingerMs / 1000),
        ));
    }

    /**
     * The latest values per device. Does not touch the stream.
     */
    public function query(): Query
    {
        return new Query($this->client);
    }

    /**
     * A long-running stream reader that dispatches events.
     */
    public function consumer(): Consumer
    {
        return new Consumer($this, $this->events, $this->account, $this->state);
    }
}
