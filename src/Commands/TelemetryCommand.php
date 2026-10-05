<?php

namespace FLAIRUK\Starlink\Commands;

use Carbon\Carbon;
use FLAIRUK\Starlink\Events\AlertCleared;
use FLAIRUK\Starlink\Events\AlertRaised;
use FLAIRUK\Starlink\Exceptions\StarlinkException;
use FLAIRUK\Starlink\Starlink;
use FLAIRUK\Starlink\Telemetry\Batch;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Read the telemetry stream and dispatch its events, until stopped.
 *
 * Run it under a process monitor such as Supervisor, one process per
 * account. Listen for TelemetryReceived, AlertRaised and AlertCleared to act
 * on what it reads.
 */
class TelemetryCommand extends Command
{
    protected $signature = 'starlink:telemetry
        {--account= : The account in config/starlink.php (default: the default account)}
        {--batch-size= : Rows per request, up to 65000}
        {--linger= : Milliseconds to collect rows per request, up to 65000}
        {--max-batches= : Stop after this many batches}';

    protected $description = 'Read the Starlink telemetry stream and dispatch telemetry and alert events';

    public function handle(Starlink $starlink, Dispatcher $events): int
    {
        $account = $this->option('account') ?: null;
        $consumer = $starlink->account($account)->telemetry()->consumer();

        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM], function () use ($consumer) {
                $this->components->info('Stopping after the current batch.');
                $consumer->stop();
            });
        }

        $events->listen(AlertRaised::class, fn (AlertRaised $e) => $this->line(sprintf(
            '  <fg=red>raised</>  %s  %s (%d)', $e->deviceId(), $e->name ?? 'unknown', $e->code,
        )));

        $events->listen(AlertCleared::class, fn (AlertCleared $e) => $this->line(sprintf(
            '  <fg=green>cleared</> %s  %s (%d)', $e->deviceId(), $e->name ?? 'unknown', $e->code,
        )));

        $this->components->info(sprintf('Reading telemetry for the [%s] account.', $account ?? $this->laravel['config']->get('starlink.default')));

        try {
            $consumer->run(
                onBatch: fn (Batch $batch) => $batch->isEmpty() ? null : $this->line(sprintf(
                    '%s  %d rows: %d terminals, %d routers, %d IP allocations',
                    Carbon::now()->format('H:i:s'),
                    count($batch),
                    $batch->userTerminals()->count(),
                    $batch->routers()->count(),
                    $batch->ipAllocations()->count(),
                )),
                maxBatches: $this->option('max-batches') !== null ? (int) $this->option('max-batches') : null,
                batchSize: $this->option('batch-size') !== null ? (int) $this->option('batch-size') : null,
                maxLingerMs: $this->option('linger') !== null ? (int) $this->option('linger') : null,
            );
        } catch (StarlinkException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
