<?php

namespace FLAIRUK\Starlink;

use FLAIRUK\Starlink\Auth\TokenProvider;
use FLAIRUK\Starlink\Exceptions\ConnectionException;
use FLAIRUK\Starlink\Exceptions\StarlinkException;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends authenticated requests to the Starlink Public API V2.
 *
 * A 401 means the token expired. The token is dropped, a new one minted, and
 * the request sent once more. Nothing else is retried here: a stream request
 * moves the service account's read position, so whether to ask again is the
 * consumer's decision.
 */
class Client
{
    public function __construct(
        protected TokenProvider $tokens,
        protected string $baseUrl = 'https://starlink.com/api',
        protected int $timeout = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], int $extraTimeout = 0): array
    {
        $response = $this->send($path, $body, $extraTimeout);

        if ($response->status() === 401) {
            $this->tokens->forget();
            $response = $this->send($path, $body, $extraTimeout);
        }

        if ($response->failed()) {
            throw StarlinkException::fromResponse($response);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new StarlinkException('Starlink answered without a JSON body.', $response->status());
        }

        // The query endpoint wraps its answer in a ServiceResponse envelope.
        // The stream endpoint does not.
        if (array_key_exists('isValid', $json)) {
            if ($json['isValid'] === false) {
                throw StarlinkException::fromResponse($response);
            }

            return is_array($json['content'] ?? null) ? $json['content'] : [];
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function send(string $path, array $body, int $extraTimeout): Response
    {
        try {
            return Http::withToken($this->tokens->token())
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout + $extraTimeout)
                ->connectTimeout(10)
                ->post(rtrim($this->baseUrl, '/').'/'.ltrim($path, '/'), (object) $body);
        } catch (HttpConnectionException $e) {
            throw new ConnectionException('Could not reach Starlink: '.$e->getMessage(), null, [], $e);
        }
    }
}
