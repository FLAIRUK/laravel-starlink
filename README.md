<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/logo-dark.svg">
    <img src="art/logo-light.svg" alt="Starlink for Laravel" width="420">
  </picture>
</p>

<h2 align="center">
  <a href="https://www.php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white" alt="PHP 8.2+"></a>&nbsp;
  <a href="https://laravel.com/docs/" target="_blank"><img src="https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat&logo=laravel&logoColor=white" alt="Laravel 12 or 13"></a>&nbsp;
  <a href="https://github.com/FLAIRUK/laravel-starlink/actions/workflows/tests.yml" target="_blank"><img src="https://img.shields.io/badge/Lint-%E2%9C%93-2EA043?style=flat&logo=githubactions&logoColor=white" alt="Lint"></a>&nbsp;
  <a href="https://github.com/FLAIRUK/laravel-starlink/actions/workflows/tests.yml" target="_blank"><img src="https://img.shields.io/badge/Tests-%E2%9C%93-2EA043?style=flat&logo=githubactions&logoColor=white" alt="Tests"></a>&nbsp;
  <a href="https://packagist.org/packages/flairuk/laravel-starlink" target="_blank"><img src="https://img.shields.io/packagist/dt/flairuk/laravel-starlink?style=flat&logo=packagist&logoColor=white&label=Downloads&color=F28D1A" alt="Downloads on Packagist"></a>&nbsp;
  <a href="https://github.com/FLAIRUK/laravel-starlink/blob/main/LICENSE.md" target="_blank"><img src="https://img.shields.io/github/license/FLAIRUK/laravel-starlink?style=flat&label=License&color=3DA639" alt="MIT licence"></a>&nbsp;
  <a href="https://starlink.readme.io/docs/telemetry-api" target="_blank"><img src="https://img.shields.io/badge/Client-Starlink%20Telemetry%20API-1E293B?style=flat" alt="Starlink Telemetry API"></a>&nbsp;
  <br>&nbsp;
</h2>

**Starlink for Laravel** — Read device telemetry from the [Starlink Telemetry API](https://starlink.readme.io/docs/telemetry-api) in Laravel 12 and 13: your terminals' throughput, latency, packet loss, obstruction and signal quality, your routers' health, and public IP allocations, with alerts turned into Laravel events.

- **The stream, decoded.** Starlink sends rows as bare arrays. Each row becomes a `Record` keyed by column name, using the column names in the same response, so a reordered column never misreads a value.
- **Alerts as events.** Starlink only says which alerts are active now. `AlertRaised` and `AlertCleared` fire when that changes, and the state survives a restart.
- **A consumer that keeps going.** `php artisan starlink:telemetry` polls the stream at Starlink's recommended pace and waits out dropped connections, rate limits and outages.
- **The latest values on demand.** `query()` reads the current values per terminal and router without moving your place in the stream.
- **Tokens handled.** OAuth client credentials, cached and replaced when they expire.
- **Several accounts.** One service account per Starlink account, chosen by name.

<p align="center">
  📦&nbsp;<a href="#-installation">Installation</a> ·
  📡&nbsp;<a href="#-the-stream">The stream</a> ·
  🚨&nbsp;<a href="#-alerts">Alerts</a> ·
  🔁&nbsp;<a href="#-running-a-consumer">Running a consumer</a> ·
  🔎&nbsp;<a href="#-latest-values">Latest values</a> ·
  ⚠️&nbsp;<a href="#%EF%B8%8F-errors">Errors</a>
</p>

<br><br>

## 📦 Installation

Requires PHP 8.2+ and Laravel 12 or 13. Laravel 13 needs PHP 8.3+.

```bash
composer require flairuk/laravel-starlink
```

Telemetry is available to Starlink enterprise accounts. On the [Starlink account settings page](https://www.starlink.com/account/settings), under **API Service Accounts**, add a service account with the **Device telemetry, View** permission, and copy its client ID and secret:

```dotenv
STARLINK_CLIENT_ID=…
STARLINK_CLIENT_SECRET=…
```

Optional settings:

| Key | Default | |
| --- | --- | --- |
| `STARLINK_BATCH_SIZE` | `1000` | Rows per stream request, up to 65000. |
| `STARLINK_MAX_LINGER_MS` | `15000` | How long one request may collect rows, up to 65000. |
| `STARLINK_TIMEOUT` | `30` | Seconds allowed on top of the linger. |
| `STARLINK_CACHE_STORE` | default store | Where tokens and alert state are kept. |
| `STARLINK_ACCOUNT` | `default` | Which account the facade uses. |

To read more than one Starlink account, publish the config and add an entry per account:

```bash
php artisan vendor:publish --tag=starlink-config
```

```php
'accounts' => [
    'default' => ['client_id' => env('STARLINK_CLIENT_ID'), 'client_secret' => env('STARLINK_CLIENT_SECRET')],
    'fleet' => ['client_id' => env('STARLINK_FLEET_CLIENT_ID'), 'client_secret' => env('STARLINK_FLEET_CLIENT_SECRET')],
],
```

```php
Starlink::account('fleet')->telemetry()->stream();
```

> [!IMPORTANT]
> Each service account has its own place in the stream, and every read moves it on. Give each environment (local, staging, production) its own service account, or they will take rows from each other.

<br>

## 📡 The stream

```php
use FLAIRUK\Starlink\Facades\Starlink;

$batch = Starlink::telemetry()->stream();

foreach ($batch->userTerminals() as $terminal) {
    $terminal->deviceId();                        // 'ut12345678-a12b3456-123456c1'
    $terminal->timestamp();                       // CarbonImmutable, UTC, to the microsecond
    $terminal->get('DownlinkThroughput');         // Mbps
    $terminal->get('PingLatencyMsAvg');
    $terminal->get('PingDropRateAvg');            // 0.0 to 1.0
    $terminal->get('ObstructionPercentTime');
    $terminal->get('SignalQuality');              // 0 to 1
    $terminal['CountryCode'];                     // array access works too
    $terminal->alerts();                          // [64 => 'high_sky_obstruction']
}

$batch->routers();          // WifiUptimeS, InternetPingLatencyMs, Clients, WanRxBytes, DishId, …
$batch->ipAllocations();    // Ipv4, Ipv6Ue, Ipv6Cpe
$batch->withAlerts();       // every row with an active alert
```

Each request returns up to `batchSize` rows collected over at most `maxLingerMs`, for every device on the account. You can't filter the stream by device. An empty batch is normal.

Things to know about the stream:

- **Every read moves the stream on.** Rows that have been returned are not sent again. Store them before doing anything that can fail.
- **Rows are kept for 8 hours.** A consumer that is down for longer loses the rows in between.
- **Poll continuously.** Terminals and routers report every 15 seconds. A consumer that reads slower than the account writes falls behind and keeps getting old rows.
- **Every value can be null**, and new columns appear over time. Read with `get('Column', $default)`. The full list of columns is in [Starlink's guide](https://starlink.readme.io/docs/telemetry-api#what-kinds-of-data-does-the-api-contain).
- **An IP allocation row with no addresses** means the terminal no longer holds public IPs: `$record->isTombstone()`.

<br>

## 🚨 Alerts

Each row lists the alert codes active on the device. They are named from the same response's metadata, which Starlink says is the only reliable mapping:

```php
$terminal->alerts();                          // [80 => 'thermal_shutdown', 64 => 'high_sky_obstruction']
$terminal->hasAlert(80);
$terminal->hasAlert('thermal_shutdown');
```

Starlink only reports what is active now. The consumer compares each device's alerts with its previous row and dispatches:

| Event | When |
| --- | --- |
| `TelemetryReceived` | A batch with at least one row arrived. Has `$account` and `$batch`. |
| `AlertRaised` | An alert is active that was not in the device's previous row. Has `$account`, `$record`, `$code`, `$name`. |
| `AlertCleared` | An alert from the device's previous row is no longer active. |

```php
use FLAIRUK\Starlink\Events\AlertRaised;

Event::listen(function (AlertRaised $event) {
    if ($event->name === 'thermal_shutdown') {
        Notification::route('slack', config('services.slack.ops'))
            ->notify(new TerminalOverheating($event->deviceId()));
    }
});
```

The alerts each device has active are kept in the cache, so a restarted consumer does not raise them all again. `$consumer->reset()` forgets them. A device that goes offline sends no rows, so its alerts stay raised until it reports again.

Starlink's [alert reference](https://starlink.readme.io/docs/alerts) lists the codes. Starlink currently documents a known issue: devices on software 2025.06.05 or later report `software_update_reboot_pending` when no reboot is pending.

<br>

## 🔁 Running a consumer

```bash
php artisan starlink:telemetry
php artisan starlink:telemetry --account=fleet --batch-size=5000 --linger=15000
```

It polls until stopped, printing a line per batch and each alert raised or cleared. Your listeners run between batches, so keep them quick and send slow work to a queue. Dropped connections, 429s and 5xx answers are retried after a pause that grows to a minute. A refused key or a missing permission stops it with an error.

Keep one process running per account under Supervisor or similar:

```ini
[program:starlink-telemetry]
command=php /var/www/app/artisan starlink:telemetry
autorestart=true
stopwaitsecs=90
```

`SIGTERM` lets the current batch finish before the process exits, so its rows reach your listeners. That needs the `pcntl` extension.

From code:

```php
$consumer = Starlink::telemetry()->consumer();

$consumer->run(
    onBatch: fn (Batch $batch) => TelemetryRow::insert(/* … */),
    maxBatches: null,               // run until stopped
    until: fn () => Cache::get('stop-starlink'),
);

$consumer->poll();                  // or one batch at a time
```

<br>

## 🔎 Latest values

When you only need each device's current state, use `query()`. It returns Starlink's typed values and does not touch the stream, so it is safe to call from a web request:

```php
$snapshot = Starlink::telemetry()->query()->get();                 // every terminal and router

$snapshot = Starlink::telemetry()->query()
    ->userTerminals('ut12345678-a12b3456-123456c1')
    ->routers()
    ->get();

$snapshot->userTerminal('ut12345678-a12b3456-123456c1');
// ['downlinkThroughputMbps' => 120.5, 'popPingLatencyMsAvg' => 31, 'signalQuality' => 0.92, …]

$snapshot->router('Router-012300205003000000000000');
$snapshot->find($id);                     // either kind
$snapshot->terminals();                   // Collection keyed by ID
```

<br>

## ⚠️ Errors

| Exception | When |
| --- | --- |
| `AuthenticationException` | The client ID or secret was refused, or a fresh token was refused too. |
| `PermissionException` | 403: the service account lacks **Device telemetry, View**. |
| `RateLimitException` | 429: more than 250 requests a minute for the account, or 1000 tokens in 15 minutes from one IP address. |
| `ConnectionException` | Starlink could not be reached. |
| `ConfigurationException` | The account is not configured, or has no client ID or secret. |
| `StarlinkException` | The base class, and the type for anything else. `$e->status` is the HTTP status and `$e->errors` Starlink's error list. |

An expired token (401) is replaced and the request sent once more before anything is thrown.

<br>

## 🧪 Testing

```bash
composer test
composer format
```

The tests use `Http::fake()` with the example responses from Starlink's documentation and never call the real API. Your own tests can do the same: fake `*/auth/connect/token` and `*/telemetry/stream`.

## License

MIT. See [LICENSE.md](LICENSE.md).
