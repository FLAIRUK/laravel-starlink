<?php

namespace FLAIRUK\Starlink\Telemetry;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * The latest telemetry per device, as Starlink's typed objects.
 *
 * User terminal fields include `uptimeSeconds`, `downlinkThroughputMbps`,
 * `uplinkThroughputMbps`, `popPingDropRateAvg`, `popPingLatencyMsAvg`,
 * `obstructionPercentTime`, `signalQuality` and `countryCode`. Router fields
 * include `uptimeSeconds`, `isRepeater`, `hopsFromController`,
 * `internetPingDropRate` and `internetPingLatencyMs`. All may be null.
 *
 * @implements Arrayable<string, mixed>
 */
class Snapshot implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, array<string, mixed>>  $userTerminals  Terminal ID to its latest values.
     * @param  array<string, array<string, mixed>>  $routers  Router ID to its latest values.
     */
    public function __construct(
        public readonly array $userTerminals = [],
        public readonly array $routers = [],
    ) {}

    /**
     * @param  array<string, mixed>  $content
     */
    public static function fromResponse(array $content): self
    {
        return new self(
            is_array($content['userTerminals'] ?? null) ? $content['userTerminals'] : [],
            is_array($content['routers'] ?? null) ? $content['routers'] : [],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userTerminal(string $id): ?array
    {
        return $this->userTerminals[$id] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function router(string $id): ?array
    {
        return $this->routers[$id] ?? null;
    }

    /**
     * A device of either kind, by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        return $this->userTerminal($id) ?? $this->router($id);
    }

    /** @return Collection<string, array<string, mixed>> */
    public function terminals(): Collection
    {
        return new Collection($this->userTerminals);
    }

    /** @return Collection<string, array<string, mixed>> */
    public function allRouters(): Collection
    {
        return new Collection($this->routers);
    }

    public function isEmpty(): bool
    {
        return $this->userTerminals === [] && $this->routers === [];
    }

    public function toArray(): array
    {
        return ['userTerminals' => $this->userTerminals, 'routers' => $this->routers];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
