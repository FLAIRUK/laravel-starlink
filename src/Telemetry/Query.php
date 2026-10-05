<?php

namespace FLAIRUK\Starlink\Telemetry;

use FLAIRUK\Starlink\Client;

/**
 * `POST /public/v2/telemetry/query`: the latest values per device.
 *
 * Unlike the stream, this does not move the service account's read
 * position, so it is safe to call from a web request or a dashboard. With no
 * filter it returns every user terminal and every router.
 */
class Query
{
    /** @var array<string, mixed> */
    protected array $body = [];

    public function __construct(
        protected Client $client,
    ) {}

    /**
     * Include user terminals: all of them, or only these IDs.
     *
     * @param  string|array<int, string>  ...$ids
     */
    public function userTerminals(string|array ...$ids): static
    {
        $this->body['includeUserTerminals'] = true;

        $ids = array_values(array_merge(...array_map(fn ($id) => (array) $id, $ids)));

        if ($ids !== []) {
            $this->body['userTerminalIds'] = array_values(array_unique([...($this->body['userTerminalIds'] ?? []), ...$ids]));
        }

        return $this;
    }

    /**
     * Include routers: all of them, or only these IDs.
     *
     * @param  string|array<int, string>  ...$ids
     */
    public function routers(string|array ...$ids): static
    {
        $this->body['includeRouters'] = true;

        $ids = array_values(array_merge(...array_map(fn ($id) => (array) $id, $ids)));

        if ($ids !== []) {
            $this->body['routerIds'] = array_values(array_unique([...($this->body['routerIds'] ?? []), ...$ids]));
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        if (! isset($this->body['includeUserTerminals']) && ! isset($this->body['includeRouters'])) {
            return ['includeUserTerminals' => true, 'includeRouters' => true];
        }

        return $this->body + ['includeUserTerminals' => false, 'includeRouters' => false];
    }

    public function get(): Snapshot
    {
        return Snapshot::fromResponse($this->client->post('public/v2/telemetry/query', $this->body()));
    }
}
