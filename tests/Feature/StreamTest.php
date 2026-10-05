<?php

namespace FLAIRUK\Starlink\Tests\Feature;

use FLAIRUK\Starlink\Exceptions\AuthenticationException;
use FLAIRUK\Starlink\Exceptions\ConfigurationException;
use FLAIRUK\Starlink\Exceptions\PermissionException;
use FLAIRUK\Starlink\Exceptions\RateLimitException;
use FLAIRUK\Starlink\Facades\Starlink;
use FLAIRUK\Starlink\Telemetry\DeviceType;
use FLAIRUK\Starlink\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class StreamTest extends TestCase
{
    public function test_it_mints_a_token_and_posts_the_batch_request(): void
    {
        Http::fake([
            'starlink.com/api/auth/connect/token' => Http::response($this->token()),
            'starlink.com/api/public/v2/telemetry/stream' => Http::response($this->streamResponse()),
        ]);

        $batch = Starlink::telemetry()->stream();

        $this->assertCount(5, $batch);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://starlink.com/api/auth/connect/token'
            && $r->isForm()
            && $r['client_id'] === 'client-id'
            && $r['client_secret'] === 'client-secret'
            && $r['grant_type'] === 'client_credentials');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://starlink.com/api/public/v2/telemetry/stream'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer token-1')
            && $r->data() === ['batchSize' => 1000, 'maxLingerMs' => 15000]);
    }

    public function test_the_token_is_cached_between_requests(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->emptyStreamResponse()),
        ]);

        Starlink::telemetry()->stream();
        Starlink::telemetry()->stream();

        Http::assertSentCount(3);
    }

    public function test_rows_are_decoded_by_the_column_names_in_the_response(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->streamResponse()),
        ]);

        $batch = Starlink::telemetry()->stream();
        $terminal = $batch->userTerminals()->first();

        $this->assertSame(DeviceType::UserTerminal, $terminal->deviceType());
        $this->assertSame('UserTerminal', $terminal->typeName);
        $this->assertSame('ut12345678-a12b3456-123456c1', $terminal->deviceId());
        $this->assertSame(47, $terminal->get('PingLatencyMsAvg'));
        $this->assertSame(0.000041, $terminal['PingDropRateAvg']);
        $this->assertNull($terminal->get('SignalQuality'));
        $this->assertSame(1001, $batch->routers()->first()->get('WifiUptimeS'));
        $this->assertCount(2, $batch->userTerminals());
        $this->assertCount(1, $batch->routers());
        $this->assertCount(2, $batch->ipAllocations());
    }

    public function test_a_changed_column_order_is_followed(): void
    {
        $response = $this->streamResponse();
        $response['data']['columnNamesByDeviceType']['r'] = ['DeviceType', 'UtcTimestampNs', 'DeviceId', 'InternetPingLatencyMs', 'WifiUptimeS'];

        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($response),
        ]);

        $router = Starlink::telemetry()->stream()->routers()->first();

        $this->assertSame(1001, $router->get('InternetPingLatencyMs'));
        $this->assertSame(50, $router->get('WifiUptimeS'));
    }

    public function test_timestamps_keep_microsecond_precision(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->streamResponse()),
        ]);

        $terminal = Starlink::telemetry()->stream()->userTerminals()->first();

        $this->assertSame(1681879752123456789, $terminal->timestampNs());
        $this->assertSame('2023-04-19 04:49:12.123456', $terminal->timestamp()->format('Y-m-d H:i:s.u'));
        $this->assertSame('UTC', $terminal->timestamp()->getTimezone()->getName());
    }

    public function test_alerts_are_named_from_the_response_metadata(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->streamResponse([64, 99])),
        ]);

        $batch = Starlink::telemetry()->stream();
        $terminal = $batch->userTerminals()->first();

        $this->assertSame([64 => 'high_sky_obstruction', 99 => null], $terminal->alerts());
        $this->assertTrue($terminal->hasAlert(64));
        $this->assertTrue($terminal->hasAlert('high_sky_obstruction'));
        $this->assertFalse($terminal->hasAlert('thermal_shutdown'));
        $this->assertCount(1, $batch->withAlerts());
        $this->assertSame('thermal_shutdown', $batch->alertNames()['80']);
    }

    public function test_an_ip_allocation_without_addresses_is_a_tombstone(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->streamResponse()),
        ]);

        [$allocated, $tombstone] = Starlink::telemetry()->stream()->ipAllocations()->all();

        $this->assertSame(['123.123.123.123'], $allocated->get('Ipv4'));
        $this->assertFalse($allocated->isTombstone());
        $this->assertTrue($tombstone->isTombstone());
    }

    public function test_batch_size_and_linger_are_capped_and_the_timeout_covers_the_linger(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->emptyStreamResponse()),
        ]);

        $this->assertTrue(Starlink::telemetry()->stream(100000, 90000)->isEmpty());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/telemetry/stream')
            && $r->data() === ['batchSize' => 65000, 'maxLingerMs' => 65000]);
    }

    public function test_an_expired_token_is_replaced_and_the_request_sent_again(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::sequence()
                ->push($this->token('old'))
                ->push($this->token('new')),
            '*/telemetry/stream' => Http::sequence()
                ->push(['errors' => [['errorMessage' => 'Unauthorized']], 'isValid' => false], 401)
                ->push($this->streamResponse()),
        ]);

        $this->assertCount(5, Starlink::telemetry()->stream());

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer new'));
    }

    public function test_a_token_refused_twice_is_an_authentication_error(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response('', 401),
        ]);

        $this->expectException(AuthenticationException::class);

        Starlink::telemetry()->stream();
    }

    public function test_refused_credentials_are_an_authentication_error(): void
    {
        Http::fake(['*/auth/connect/token' => Http::response(['error' => 'invalid_client'], 400)]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('invalid_client');

        Starlink::telemetry()->stream();
    }

    public function test_a_missing_permission_is_a_permission_error(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response([
                'errors' => [['memberNames' => [], 'errorMessage' => 'user_lacks_required_permission']],
                'isValid' => false,
            ], 403),
        ]);

        try {
            Starlink::telemetry()->stream();
            $this->fail('Expected a PermissionException.');
        } catch (PermissionException $e) {
            $this->assertSame(403, $e->status);
            $this->assertStringContainsString('Device telemetry, View', $e->getMessage());
            $this->assertStringContainsString('user_lacks_required_permission', $e->getMessage());
            $this->assertSame('user_lacks_required_permission', $e->errors[0]['errorMessage']);
        }
    }

    public function test_a_rate_limit_is_a_rate_limit_error(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response('', 429),
        ]);

        $this->expectException(RateLimitException::class);

        Starlink::telemetry()->stream();
    }

    public function test_named_accounts_use_their_own_credentials(): void
    {
        config(['starlink.accounts.fleet' => ['client_id' => 'fleet-id', 'client_secret' => 'fleet-secret']]);

        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->emptyStreamResponse()),
        ]);

        Starlink::account('fleet')->telemetry()->stream();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/token') && $r['client_id'] === 'fleet-id');
        $this->assertSame(['default', 'fleet'], Starlink::accounts());
    }

    public function test_an_unknown_or_incomplete_account_is_a_configuration_error(): void
    {
        $this->expectException(ConfigurationException::class);

        Starlink::account('nope');
    }

    public function test_missing_credentials_are_a_configuration_error(): void
    {
        config(['starlink.accounts.empty' => ['client_id' => null, 'client_secret' => null]]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('STARLINK_CLIENT_ID');

        Starlink::account('empty');
    }
}
