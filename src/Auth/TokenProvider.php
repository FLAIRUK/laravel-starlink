<?php

namespace FLAIRUK\Starlink\Auth;

use FLAIRUK\Starlink\Exceptions\AuthenticationException;
use FLAIRUK\Starlink\Exceptions\ConnectionException;
use FLAIRUK\Starlink\Exceptions\StarlinkException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Mints and caches bearer tokens with the OAuth client credentials grant.
 *
 * A token is reused until it expires or a request is refused with a 401.
 * Then `forget()` drops it and the next `token()` mints a new one.
 */
class TokenProvider
{
    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected string $tokenUrl,
        protected Repository $cache,
        protected string $prefix = 'starlink:token:',
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get($this->key());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->mint();
    }

    public function forget(): void
    {
        $this->cache->forget($this->key());
    }

    protected function mint(): string
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(15)
                ->post($this->tokenUrl, [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ]);
        } catch (HttpConnectionException $e) {
            throw new ConnectionException('Could not reach the Starlink token endpoint: '.$e->getMessage(), null, [], $e);
        }

        if ($response->status() === 400 || $response->status() === 401) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'invalid_client';

            throw new AuthenticationException(
                "Starlink refused the service account credentials: {$error}",
                $response->status(),
            );
        }

        if ($response->failed()) {
            throw StarlinkException::fromResponse($response);
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new AuthenticationException('Starlink did not return an access token.', $response->status());
        }

        // Tokens last about 15 minutes. Drop them a minute early so a request
        // never leaves with a token that expires on the way.
        $lifetime = (int) ($response->json('expires_in') ?? 900);

        $this->cache->put($this->key(), $token, max(30, $lifetime - 60));

        return $token;
    }

    protected function key(): string
    {
        return $this->prefix.sha1($this->clientId);
    }
}
