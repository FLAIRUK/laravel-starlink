<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service accounts
    |--------------------------------------------------------------------------
    |
    | A V2 service account is created under "API Service Accounts" on the
    | Starlink account settings page, and needs the "Device telemetry, View"
    | permission. Each service account belongs to one Starlink account, so
    | reading several Starlink accounts means one entry per account here.
    |
    | Each service account also has its own position in the telemetry
    | stream. Give each environment (local, staging, production) its own
    | service account, or they will take batches from each other.
    |
    */

    'default' => env('STARLINK_ACCOUNT', 'default'),

    'accounts' => [

        'default' => [
            'client_id' => env('STARLINK_CLIENT_ID'),
            'client_secret' => env('STARLINK_CLIENT_SECRET'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    */

    'base_url' => env('STARLINK_BASE_URL', 'https://starlink.com/api'),

    'token_url' => env('STARLINK_TOKEN_URL', 'https://starlink.com/api/auth/connect/token'),

    /*
    |--------------------------------------------------------------------------
    | Stream polling
    |--------------------------------------------------------------------------
    |
    | Starlink recommends about 1000 records and a 15 second linger per
    | request, which works out at about four requests a minute. Both can go
    | up to 65000. A request may wait for the whole linger before it answers,
    | so the HTTP timeout is the linger plus `timeout`.
    |
    */

    'batch_size' => (int) env('STARLINK_BATCH_SIZE', 1000),

    'max_linger_ms' => (int) env('STARLINK_MAX_LINGER_MS', 15000),

    'timeout' => (int) env('STARLINK_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Token cache
    |--------------------------------------------------------------------------
    |
    | Bearer tokens last about 15 minutes. They are cached and reused until
    | they expire or a request is refused with a 401. The token endpoint is
    | limited to 1000 requests per 15 minutes per IP address. Leave the store
    | null to use the application's default cache.
    |
    */

    'cache' => [
        'store' => env('STARLINK_CACHE_STORE'),
        'prefix' => 'starlink:token:',
    ],

];
