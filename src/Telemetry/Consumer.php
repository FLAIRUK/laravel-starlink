<?php

namespace FLAIRUK\Starlink\Telemetry;

use FLAIRUK\Starlink\Events\AlertCleared;
use FLAIRUK\Starlink\Events\AlertRaised;
use FLAIRUK\Starlink\Events\TelemetryReceived;
use FLAIRUK\Starlink\Exceptions\ConnectionException;
use FLAIRUK\Starlink\Exceptions\RateLimitException;
use FLAIRUK\Starlink\Exceptions\StarlinkException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Sleep;

/**
 * Polls the telemetry stream in a loop and turns alerts into events.
 *
 * Starlink only reports which alerts are active now. The consumer remembers
 * each device's alerts from its previous row and dispatches AlertRaised and
 * AlertCleared when they change. That memory is kept in the cache, so a
 * restarted consumer does not raise every active alert again.
 *
 * A device that goes offline sends no rows, so its alerts stay raised until
 * it comes back.
 */
class Consumer
{
    /** @var array<string, array<int, string|null>> Device key to its active alerts. */
    protected array $active = [];

    protected bool $loaded = false;

    protected bool $stopping = false;

    public function __construct(
        protected Telemetry $telemetry,
        protected Dispatcher $events,
        protected string $account,
        protected ?Repository $state = null,
    ) {}

    /**
     * Ask for one batch, dispatch its events, and return it.
     */
    public function poll(?int $batchSize = null, ?int $maxLingerMs = null): Batch
    {
        $this->load();

        $batch = $this->telemetry->stream($batchSize, $maxLingerMs);

        if ($batch->isEmpty()) {
            return $batch;
        }

        $this->events->dispatch(new TelemetryReceived($this->account, $batch));

        foreach ($batch as $record) {
            $this->compare($record);
        }

        $this->save();

        return $batch;
    }

    /**
     * Poll until stopped, `$maxBatches` is reached, or `$until` returns true.
     *
     * Dropped connections, rate limits and 5xx answers are waited out with a
     * growing pause of up to a minute. A refused key or a missing permission
     * stops the loop: waiting would not fix either.
     *
     * @param  (callable(Batch): void)|null  $onBatch
     * @param  (callable(): bool)|null  $until
     * @return int The number of batches received.
     */
    public function run(
        ?callable $onBatch = null,
        ?int $maxBatches = null,
        ?callable $until = null,
        ?int $batchSize = null,
        ?int $maxLingerMs = null,
    ): int {
        $batches = 0;
        $failures = 0;
        $this->stopping = false;

        while (! $this->stopping) {
            try {
                $batch = $this->poll($batchSize, $maxLingerMs);
                $failures = 0;
            } catch (ConnectionException|RateLimitException $e) {
                $this->pause(++$failures, $e instanceof RateLimitException ? 15 : 1);

                continue;
            } catch (StarlinkException $e) {
                if (($e->status ?? 0) < 500) {
                    throw $e;
                }

                $this->pause(++$failures, 1);

                continue;
            }

            $batches++;

            if ($onBatch !== null) {
                $onBatch($batch);
            }

            if (($maxBatches !== null && $batches >= $maxBatches) || ($until !== null && $until())) {
                break;
            }
        }

        return $batches;
    }

    /**
     * Finish the current batch, then leave `run()`.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * The alerts the consumer believes are active, per device.
     *
     * @return array<string, array<int, string|null>>
     */
    public function activeAlerts(): array
    {
        $this->load();

        return array_filter($this->active);
    }

    /**
     * Forget every remembered alert, so the next rows raise them afresh.
     */
    public function reset(): void
    {
        $this->active = [];
        $this->loaded = true;
        $this->state?->forget($this->stateKey());
    }

    protected function compare(Record $record): void
    {
        if (! $record->reportsAlerts() || $record->deviceId() === null) {
            return;
        }

        $key = $record->type.':'.$record->deviceId();
        $before = $this->active[$key] ?? [];
        $now = $record->alerts();

        foreach (array_diff_key($now, $before) as $code => $name) {
            $this->events->dispatch(new AlertRaised($this->account, $record, $code, $name));
        }

        foreach (array_diff_key($before, $now) as $code => $name) {
            $this->events->dispatch(new AlertCleared($this->account, $record, $code, $record->alertName($code) ?? $name));
        }

        $this->active[$key] = $now;
    }

    protected function pause(int $failures, int $base): void
    {
        Sleep::for(min(60, $base * 2 ** min($failures - 1, 6)))->seconds();
    }

    protected function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $stored = $this->state?->get($this->stateKey());
        $this->active = is_array($stored) ? $stored : [];
    }

    protected function save(): void
    {
        $this->state?->forever($this->stateKey(), array_filter($this->active));
    }

    protected function stateKey(): string
    {
        return 'starlink:alerts:'.$this->account;
    }
}
