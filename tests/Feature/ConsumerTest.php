<?php

namespace FLAIRUK\Starlink\Tests\Feature;

use FLAIRUK\Starlink\Events\AlertCleared;
use FLAIRUK\Starlink\Events\AlertRaised;
use FLAIRUK\Starlink\Events\TelemetryReceived;
use FLAIRUK\Starlink\Exceptions\PermissionException;
use FLAIRUK\Starlink\Facades\Starlink;
use FLAIRUK\Starlink\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class ConsumerTest extends TestCase
{
    public function test_alerts_are_raised_and_cleared_as_they_change(): void
    {
        Event::fake();

        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::sequence()
                ->push($this->streamResponse([64, 79]))
                ->push($this->streamResponse([64, 80]))
                ->push($this->emptyStreamResponse()),
        ]);

        $consumer = Starlink::telemetry()->consumer();

        $consumer->poll();

        Event::assertDispatched(TelemetryReceived::class, fn ($e) => $e->account === 'default' && count($e->batch) === 5);
        Event::assertDispatchedTimes(AlertRaised::class, 2);
        Event::assertDispatched(AlertRaised::class, fn ($e) => $e->code === 64 && $e->name === 'high_sky_obstruction'
            && $e->deviceId() === 'ut12345678-a12b3456-123456c1');
        Event::assertNotDispatched(AlertCleared::class);

        $consumer->poll();

        Event::assertDispatchedTimes(AlertRaised::class, 3);
        Event::assertDispatched(AlertRaised::class, fn ($e) => $e->code === 80 && $e->name === 'thermal_shutdown');
        Event::assertDispatchedTimes(AlertCleared::class, 1);
        Event::assertDispatched(AlertCleared::class, fn ($e) => $e->code === 79 && $e->name === 'software_update_reboot_pending');

        $this->assertSame(
            ['u:ut12345678-a12b3456-123456c1' => [64 => 'high_sky_obstruction', 80 => 'thermal_shutdown']],
            $consumer->activeAlerts(),
        );

        // An empty batch says nothing about alerts and dispatches nothing.
        $consumer->poll();

        Event::assertDispatchedTimes(TelemetryReceived::class, 2);
        Event::assertDispatchedTimes(AlertCleared::class, 1);
    }

    public function test_a_restarted_consumer_remembers_active_alerts(): void
    {
        Event::fake();

        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->streamResponse([64])),
        ]);

        Starlink::telemetry()->consumer()->poll();
        Starlink::telemetry()->consumer()->poll();

        Event::assertDispatchedTimes(AlertRaised::class, 1);
    }

    public function test_reset_forgets_the_remembered_alerts(): void
    {
        Event::fake();

        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->streamResponse([64])),
        ]);

        Starlink::telemetry()->consumer()->poll();

        $consumer = Starlink::telemetry()->consumer();
        $consumer->reset();
        $consumer->poll();

        Event::assertDispatchedTimes(AlertRaised::class, 2);
    }

    public function test_run_waits_out_dropped_connections_and_rate_limits(): void
    {
        Sleep::fake();
        Event::fake();

        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::sequence()
                ->pushFailedConnection()
                ->push('', 429)
                ->push('', 503)
                ->push($this->streamResponse()),
        ]);

        $seen = [];

        $batches = Starlink::telemetry()->consumer()->run(
            onBatch: function ($batch) use (&$seen) {
                $seen[] = count($batch);
            },
            maxBatches: 1,
        );

        $this->assertSame(1, $batches);
        $this->assertSame([5], $seen);

        Sleep::assertSequence([
            Sleep::for(1)->seconds(),
            Sleep::for(30)->seconds(),
            Sleep::for(4)->seconds(),
        ]);
    }

    public function test_run_stops_on_a_missing_permission(): void
    {
        Sleep::fake();

        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response('', 403),
        ]);

        $this->expectException(PermissionException::class);

        Starlink::telemetry()->consumer()->run();
    }

    public function test_run_stops_when_until_says_so(): void
    {
        Event::fake();

        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->emptyStreamResponse()),
        ]);

        $calls = 0;

        $batches = Starlink::telemetry()->consumer()->run(until: function () use (&$calls) {
            return ++$calls === 3;
        });

        $this->assertSame(3, $batches);
    }

    public function test_the_command_reads_the_stream_and_reports_alerts(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response($this->streamResponse([80])),
        ]);

        $this->artisan('starlink:telemetry', ['--max-batches' => 1, '--batch-size' => 500, '--linger' => 5000])
            ->expectsOutputToContain('5 rows: 2 terminals, 1 routers, 2 IP allocations')
            ->expectsOutputToContain('thermal_shutdown (80)')
            ->assertSuccessful();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/telemetry/stream')
            && $r->data() === ['batchSize' => 500, 'maxLingerMs' => 5000]);
    }

    public function test_the_command_fails_cleanly_on_a_missing_permission(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/stream' => Http::response('', 403),
        ]);

        $this->artisan('starlink:telemetry')
            ->expectsOutputToContain('Device telemetry, View')
            ->assertFailed();
    }
}
