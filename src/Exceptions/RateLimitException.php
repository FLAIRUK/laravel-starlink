<?php

namespace FLAIRUK\Starlink\Exceptions;

/**
 * Too many requests (429): 250 a minute per Starlink account, or 1000 tokens per 15 minutes per IP address.
 */
class RateLimitException extends StarlinkException {}
