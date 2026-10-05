<?php

namespace FLAIRUK\Starlink;

use FLAIRUK\Starlink\Auth\TokenProvider;
use FLAIRUK\Starlink\Exceptions\ConfigurationException;
use FLAIRUK\Starlink\Telemetry\Telemetry;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The way in. Calls go to the default account unless another is named.
 *
 *     Starlink::telemetry()->stream();
 *     Starlink::telemetry()->query()->userTerminals()->get();
 *     Starlink::account('fleet')->telemetry()->consumer()->run();
 */
class Starlink
{
    /** @var array<string, Account> */
    protected array $accounts = [];

    /**
     * @param  array<string, mixed>  $config  The `starlink` config.
     */
    public function __construct(
        protected array $config,
        protected CacheFactory $cache,
        protected Dispatcher $events,
    ) {}

    public function account(?string $name = null): Account
    {
        $name ??= (string) ($this->config['default'] ?? 'default');

        return $this->accounts[$name] ??= $this->resolve($name);
    }

    public function telemetry(): Telemetry
    {
        return $this->account()->telemetry();
    }

    /**
     * The names of the configured accounts.
     *
     * @return array<int, string>
     */
    public function accounts(): array
    {
        return array_keys($this->config['accounts'] ?? []);
    }

    protected function resolve(string $name): Account
    {
        $account = $this->config['accounts'][$name] ?? null;

        if (! is_array($account)) {
            throw new ConfigurationException("The Starlink account [{$name}] is not configured in config/starlink.php.");
        }

        if (empty($account['client_id']) || empty($account['client_secret'])) {
            throw new ConfigurationException("The Starlink account [{$name}] has no client ID or secret. Set STARLINK_CLIENT_ID and STARLINK_CLIENT_SECRET.");
        }

        $cache = $this->cache->store($this->config['cache']['store'] ?? null);

        $tokens = new TokenProvider(
            (string) $account['client_id'],
            (string) $account['client_secret'],
            (string) ($this->config['token_url'] ?? 'https://starlink.com/api/auth/connect/token'),
            $cache,
            (string) ($this->config['cache']['prefix'] ?? 'starlink:token:'),
        );

        $client = new Client(
            $tokens,
            (string) ($this->config['base_url'] ?? 'https://starlink.com/api'),
            (int) ($this->config['timeout'] ?? 30),
        );

        $telemetry = new Telemetry(
            $client,
            $name,
            $this->events,
            $cache,
            (int) ($this->config['batch_size'] ?? 1000),
            (int) ($this->config['max_linger_ms'] ?? 15000),
        );

        return new Account($name, $client, $telemetry);
    }
}
