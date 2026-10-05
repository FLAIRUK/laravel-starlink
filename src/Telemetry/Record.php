<?php

namespace FLAIRUK\Starlink\Telemetry;

use ArrayAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use LogicException;

/**
 * One telemetry row, with its values keyed by column name.
 *
 * Every value may be null, and a column may be missing from older rows, so
 * read values with `get()` and a default rather than assuming they are set.
 *
 * @implements ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 */
class Record implements Arrayable, ArrayAccess, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $values  Column name to value, including DeviceType, UtcTimestampNs and DeviceId.
     * @param  array<int|string, string>  $alertNames  Alert code to name for this device type, from the same response.
     */
    public function __construct(
        public readonly string $type,
        public readonly array $values,
        public readonly ?string $typeName = null,
        protected array $alertNames = [],
    ) {}

    public function deviceType(): ?DeviceType
    {
        return DeviceType::tryFrom($this->type);
    }

    public function deviceId(): ?string
    {
        $id = $this->values['DeviceId'] ?? null;

        return $id === null ? null : (string) $id;
    }

    public function isUserTerminal(): bool
    {
        return $this->type === DeviceType::UserTerminal->value;
    }

    public function isRouter(): bool
    {
        return $this->type === DeviceType::Router->value;
    }

    public function isIpAllocation(): bool
    {
        return $this->type === DeviceType::IpAllocs->value;
    }

    /**
     * When the device produced this row, in nanoseconds since the epoch.
     */
    public function timestampNs(): ?int
    {
        $ns = $this->values['UtcTimestampNs'] ?? null;

        return is_numeric($ns) ? (int) $ns : null;
    }

    /**
     * When the device produced this row, to the microsecond.
     */
    public function timestamp(): ?CarbonImmutable
    {
        $ns = $this->timestampNs();

        if ($ns === null) {
            return null;
        }

        $seconds = intdiv($ns, 1_000_000_000);
        $micros = intdiv($ns % 1_000_000_000, 1_000);

        $timestamp = CarbonImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros));

        return $timestamp ? $timestamp->setTimezone('UTC') : null;
    }

    public function has(string $column): bool
    {
        return array_key_exists($column, $this->values);
    }

    public function get(string $column, mixed $default = null): mixed
    {
        return $this->values[$column] ?? $default;
    }

    /**
     * Whether the row carries an ActiveAlert column at all. A row without one
     * says nothing about alerts; a row with an empty one says none are active.
     */
    public function reportsAlerts(): bool
    {
        return $this->has('ActiveAlert');
    }

    /**
     * The alerts active on the device, as code => name. The name is null when
     * the response did not name the code.
     *
     * @return array<int, string|null>
     */
    public function alerts(): array
    {
        $codes = $this->values['ActiveAlert'] ?? [];

        if (! is_array($codes)) {
            $codes = [$codes];
        }

        $alerts = [];

        foreach ($codes as $code) {
            if (is_numeric($code)) {
                $alerts[(int) $code] = $this->alertName((int) $code);
            }
        }

        return $alerts;
    }

    public function hasAlert(int|string $alert): bool
    {
        $alerts = $this->alerts();

        return is_int($alert)
            ? array_key_exists($alert, $alerts)
            : in_array($alert, $alerts, true);
    }

    public function alertName(int $code): ?string
    {
        return $this->alertNames[$code] ?? $this->alertNames[(string) $code] ?? null;
    }

    /**
     * An IP allocation row with no addresses: the terminal no longer holds
     * public IPs, and no more allocation rows will come for it.
     */
    public function isTombstone(): bool
    {
        return $this->isIpAllocation()
            && empty($this->values['Ipv4'])
            && empty($this->values['Ipv6Ue'])
            && empty($this->values['Ipv6Cpe']);
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function jsonSerialize(): array
    {
        return $this->values;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Telemetry records are read only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Telemetry records are read only.');
    }
}
