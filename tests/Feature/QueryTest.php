<?php

namespace FLAIRUK\Starlink\Tests\Feature;

use FLAIRUK\Starlink\Exceptions\StarlinkException;
use FLAIRUK\Starlink\Facades\Starlink;
use FLAIRUK\Starlink\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class QueryTest extends TestCase
{
    private function fakeQuery(array $content = []): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/query' => Http::response([
                'errors' => [],
                'warnings' => [],
                'information' => [],
                'isValid' => true,
                'content' => $content,
            ]),
        ]);
    }

    public function test_with_no_filter_it_asks_for_every_terminal_and_router(): void
    {
        $this->fakeQuery([
            'userTerminals' => [
                'ut1' => ['userTerminalId' => 'ut1', 'downlinkThroughputMbps' => 120.5, 'signalQuality' => 0.9],
            ],
            'routers' => [
                'Router-1' => ['routerId' => 'Router-1', 'uptimeSeconds' => 3600],
            ],
        ]);

        $snapshot = Starlink::telemetry()->query()->get();

        $this->assertSame(120.5, $snapshot->userTerminal('ut1')['downlinkThroughputMbps']);
        $this->assertSame(3600, $snapshot->router('Router-1')['uptimeSeconds']);
        $this->assertSame('Router-1', $snapshot->find('Router-1')['routerId']);
        $this->assertNull($snapshot->find('missing'));
        $this->assertCount(1, $snapshot->terminals());
        $this->assertFalse($snapshot->isEmpty());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/public/v2/telemetry/query')
            && $r->data() === ['includeUserTerminals' => true, 'includeRouters' => true]);
    }

    public function test_it_filters_to_the_requested_devices(): void
    {
        $this->fakeQuery();

        Starlink::telemetry()->query()->userTerminals('ut1', ['ut2', 'ut1'])->get();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/telemetry/query') && $r->data() === [
            'includeUserTerminals' => true,
            'userTerminalIds' => ['ut1', 'ut2'],
            'includeRouters' => false,
        ]);

        $this->assertSame(
            ['includeRouters' => true, 'includeUserTerminals' => false],
            Starlink::telemetry()->query()->routers()->body(),
        );
    }

    public function test_an_invalid_envelope_is_an_error(): void
    {
        Http::fake([
            '*/auth/connect/token' => Http::response($this->token()),
            '*/telemetry/query' => Http::response([
                'errors' => [['memberNames' => ['userTerminalIds'], 'errorMessage' => 'not_found']],
                'isValid' => false,
            ], 422),
        ]);

        try {
            Starlink::telemetry()->query()->userTerminals('nope')->get();
            $this->fail('Expected a StarlinkException.');
        } catch (StarlinkException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('not_found', $e->getMessage());
        }
    }

    public function test_an_empty_snapshot(): void
    {
        $this->fakeQuery(['userTerminals' => null, 'routers' => null]);

        $this->assertTrue(Starlink::telemetry()->query()->get()->isEmpty());
    }
}
