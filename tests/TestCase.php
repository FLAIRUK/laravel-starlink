<?php

namespace FLAIRUK\Starlink\Tests;

use FLAIRUK\Starlink\StarlinkServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [StarlinkServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('starlink.accounts.default', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ]);
    }

    /**
     * A token response from the token endpoint.
     *
     * @return array<string, mixed>
     */
    protected function token(string $token = 'token-1'): array
    {
        return ['access_token' => $token, 'expires_in' => 900, 'token_type' => 'Bearer'];
    }

    /**
     * The example response from the Starlink telemetry guide, with alerts.
     *
     * @param  array<int, int>  $alerts  Active alert codes for the first terminal.
     * @return array<string, mixed>
     */
    protected function streamResponse(array $alerts = [64, 79]): array
    {
        return [
            'data' => [
                'columnNamesByDeviceType' => [
                    'u' => ['DeviceType', 'UtcTimestampNs', 'DeviceId', 'PingLatencyMsAvg', 'PingDropRateAvg', 'ActiveAlert'],
                    'r' => ['DeviceType', 'UtcTimestampNs', 'DeviceId', 'WifiUptimeS', 'InternetPingLatencyMs'],
                    'i' => ['DeviceType', 'UtcTimestampNs', 'DeviceId', 'Ipv4', 'Ipv6Ue', 'Ipv6Cpe'],
                ],
                'values' => [
                    ['u', 1681879752123456789, 'ut12345678-a12b3456-123456c1', 47, 0.000041, $alerts],
                    ['u', 1681879752000000000, 'ut12345678-a12b3456-123456c2', 48, 0, []],
                    ['r', 1695688197078032600, 'Router-012300205003000000000000', 1001, 50],
                    ['i', 1681879752000000000, 'ut12345678-a12b3456-123456c1', ['123.123.123.123'], ['1234:1245:1256::1267/64'], ['1234:1245:1256::2345/56']],
                    ['i', 1681879753000000000, 'ut12345678-a12b3456-123456c3', [], [], []],
                ],
            ],
            'metadata' => [
                'enums' => [
                    'DeviceType' => ['u' => 'UserTerminal', 'r' => 'Router', 'i' => 'IpAllocs'],
                    'AlertsByDeviceType' => [
                        'u' => ['64' => 'high_sky_obstruction', '79' => 'software_update_reboot_pending', '80' => 'thermal_shutdown'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyStreamResponse(): array
    {
        return ['data' => ['columnNamesByDeviceType' => [], 'values' => []], 'metadata' => []];
    }
}
