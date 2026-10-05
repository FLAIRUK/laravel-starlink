<?php

namespace FLAIRUK\Starlink;

use FLAIRUK\Starlink\Telemetry\Telemetry;

/**
 * One Starlink account, reached through its V2 service account.
 */
class Account
{
    public function __construct(
        public readonly string $name,
        protected Client $client,
        protected Telemetry $telemetry,
    ) {}

    public function telemetry(): Telemetry
    {
        return $this->telemetry;
    }

    public function client(): Client
    {
        return $this->client;
    }
}
