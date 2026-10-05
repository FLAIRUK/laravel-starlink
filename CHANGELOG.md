# Changelog

All notable changes to `laravel-starlink` will be documented in this file.

## 1.0.0 - 2026-10-05

First release, for Laravel 12 and 13 (PHP 8.2+), covering the Starlink Telemetry API.

- OAuth client credentials for V2 service accounts, with tokens cached and replaced on a 401.
- `telemetry()->stream()`: batches from `/public/v2/telemetry/stream`, decoded into `Record`s using the column names in each response, with microsecond UTC timestamps, alert codes named from the response metadata, and IP allocation tombstones.
- `telemetry()->query()`: the latest values per terminal and router from `/public/v2/telemetry/query`, filterable by ID.
- A `Consumer` that polls continuously, dispatches `TelemetryReceived`, `AlertRaised` and `AlertCleared`, keeps alert state in the cache across restarts, and waits out dropped connections, rate limits and 5xx answers.
- `php artisan starlink:telemetry` to run the consumer, stopping cleanly on `SIGTERM`.
- Several Starlink accounts, each with its own service account.
- Typed exceptions: `AuthenticationException`, `PermissionException`, `RateLimitException`, `ConnectionException` and `ConfigurationException`, all extending `StarlinkException`.
