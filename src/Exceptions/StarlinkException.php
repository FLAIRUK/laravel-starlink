<?php

namespace FLAIRUK\Starlink\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Starlink refused a request, or could not be reached.
 *
 * Starlink reports errors in the same envelope it uses for success:
 * `{isValid: false, errors: [{memberNames, errorMessage}]}`. The messages are
 * kept in `$errors`, and the HTTP status in `$status`.
 */
class StarlinkException extends RuntimeException
{
    /**
     * @param  array<int, array{memberNames?: array<int, string>|null, errorMessage?: string|null}>  $errors
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $errors = is_array($body) && is_array($body['errors'] ?? null) ? $body['errors'] : [];

        $messages = array_filter(array_map(
            fn ($error) => is_array($error) ? ($error['errorMessage'] ?? null) : null,
            $errors,
        ));

        $detail = $messages !== [] ? implode('; ', $messages) : trim($response->body());
        $detail = $detail !== '' ? $detail : $response->reason();

        $class = match ($response->status()) {
            401 => AuthenticationException::class,
            403 => PermissionException::class,
            429 => RateLimitException::class,
            default => self::class,
        };

        $prefix = match ($response->status()) {
            403 => 'The service account lacks the "Device telemetry, View" permission',
            429 => 'Starlink rate limit reached',
            default => sprintf('Starlink answered HTTP %d', $response->status()),
        };

        return new $class($prefix.': '.$detail, $response->status(), $errors);
    }
}
