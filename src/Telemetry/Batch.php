<?php

namespace FLAIRUK\Starlink\Telemetry;

use ArrayIterator;
use Countable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;

/**
 * One response from the telemetry stream, decoded.
 *
 * Starlink sends rows as bare arrays, with the column names for each device
 * type alongside them. Column order can change between releases, so the
 * names are read from every response, never assumed. Alert names come from
 * the same response's metadata for the same reason.
 *
 * @implements IteratorAggregate<int, Record>
 */
class Batch implements Countable, IteratorAggregate
{
    /**
     * @param  array<int, Record>  $records
     * @param  array<string, array<int, string>>  $columns  Device type code to column names.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $records,
        public readonly array $columns = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body): self
    {
        $columns = $body['data']['columnNamesByDeviceType'] ?? [];
        $rows = $body['data']['values'] ?? [];
        $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];

        $columns = is_array($columns) ? $columns : [];
        $typeNames = $metadata['enums']['DeviceType'] ?? [];
        $alertNames = $metadata['enums']['AlertsByDeviceType'] ?? [];

        $records = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || $row === []) {
                continue;
            }

            $type = (string) $row[0];
            $names = $columns[$type] ?? [];
            $values = [];

            foreach ($row as $index => $value) {
                $values[$names[$index] ?? "Column{$index}"] = $value;
            }

            $records[] = new Record(
                $type,
                $values,
                $typeNames[$type] ?? null,
                is_array($alertNames[$type] ?? null) ? $alertNames[$type] : [],
            );
        }

        return new self($records, $columns, $metadata);
    }

    /**
     * Rows of one device type: a DeviceType or its code.
     *
     * @return Collection<int, Record>
     */
    public function ofType(DeviceType|string $type): Collection
    {
        $code = $type instanceof DeviceType ? $type->value : $type;

        return $this->collect()->filter(fn (Record $record) => $record->type === $code)->values();
    }

    /** @return Collection<int, Record> */
    public function userTerminals(): Collection
    {
        return $this->ofType(DeviceType::UserTerminal);
    }

    /** @return Collection<int, Record> */
    public function routers(): Collection
    {
        return $this->ofType(DeviceType::Router);
    }

    /** @return Collection<int, Record> */
    public function ipAllocations(): Collection
    {
        return $this->ofType(DeviceType::IpAllocs);
    }

    /**
     * Rows with at least one active alert.
     *
     * @return Collection<int, Record>
     */
    public function withAlerts(): Collection
    {
        return $this->collect()->filter(fn (Record $record) => $record->alerts() !== [])->values();
    }

    /**
     * The alert code to name map this response sent for a device type.
     *
     * @return array<int|string, string>
     */
    public function alertNames(DeviceType|string $type = DeviceType::UserTerminal): array
    {
        $code = $type instanceof DeviceType ? $type->value : $type;
        $names = $this->metadata['enums']['AlertsByDeviceType'][$code] ?? [];

        return is_array($names) ? $names : [];
    }

    /**
     * An empty batch is normal: telemetry is not in every response.
     */
    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    /** @return Collection<int, Record> */
    public function collect(): Collection
    {
        return new Collection($this->records);
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->records);
    }
}
