<?php

declare(strict_types=1);

namespace Plugins\Usage\API\Contracts;

use Plugins\Usage\Domain\Growth;
use Plugins\Usage\Domain\Point;
use Plugins\Usage\Domain\Resolution;
use Plugins\Usage\Domain\RollupReport;

/**
 * Counters, gauges, growth over a window.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WRITING IS BUFFERED, AND NEVER DURABLE ON THE REQUEST PATH
 * ──────────────────────────────────────────────────────────────────────────
 * {@see count()} and {@see gauge()} add to an in-memory accumulator that belongs
 * to the current request. Nothing is written. At `after.execute` the plugin's
 * stage drains the accumulator into ONE queue message; a worker applies it to
 * the database later. So a metered request pays an array write and, at the end,
 * one queue push — never a database round trip.
 *
 * Outside HTTP (a job, a command) nothing flushes automatically: call
 * {@see flush()} when the unit of work is done.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * READING IS EVENTUALLY CONSISTENT
 * ──────────────────────────────────────────────────────────────────────────
 * A measurement is visible once the worker has applied its batch. Anything
 * that acts on these numbers (an alert, a quota) must tolerate that lag, and a
 * stopped worker shows up as every metric going flat, not as an error.
 *
 * `$tenant` on a read: null = every tenant added together, a string = that
 * tenant, '' = measurements with no tenant.
 */
interface UsageContract
{
    /** @throws \InvalidArgumentException on a bad name or a negative amount */
    public function count(string $metric, int $by = 1): void;

    /** @throws \InvalidArgumentException on a bad name or a non-finite value */
    public function gauge(string $metric, int|float $value): void;

    /**
     * Enqueue what this request/job has measured so far.
     *
     * Never throws: a metering outage must not fail the work being metered.
     *
     * @return bool true when something was queued; false when there was nothing
     *              to send OR it could not be queued (the cause is logged).
     */
    public function flush(): bool;

    /** Sum of a counter over [$from, $to). */
    public function sum(string $metric, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $tenant = null): int;

    /** The most recently reported value of a gauge, or null if it never was. */
    public function latest(string $metric, ?string $tenant = null): ?float;

    /**
     * Buckets in [$from, $to), oldest first, only those that exist.
     *
     * @return list<Point>
     */
    public function series(
        string $metric,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        Resolution $resolution = Resolution::Hour,
        ?string $tenant = null,
    ): array;

    /**
     * A counter's last window against the window before it.
     *
     * Windows are aligned to the hour so the same call always compares the same
     * rows: the current window ends at the END of the current hour (so the
     * partial hour is included) and is `$windowSeconds` long.
     *
     * @param int $windowSeconds a positive multiple of 3600
     * @throws \InvalidArgumentException
     */
    public function growth(string $metric, int $windowSeconds, ?string $tenant = null): Growth;

    /** Fold hourly buckets into daily ones and delete what is past retention. */
    public function rollup(): RollupReport;

    /** One line for status output. Never a credential or a DSN. */
    public function describe(): string;
}
