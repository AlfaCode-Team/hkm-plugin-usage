<?php

declare(strict_types=1);

namespace Plugins\Usage\Application\Ports;

use Plugins\Usage\Domain\Point;
use Plugins\Usage\Domain\Resolution;

interface UsageStore
{
    /**
     * Apply one flushed batch atomically, exactly once per `$batchId`.
     *
     * @param array<string, int>       $counters
     * @param array<string, int|float> $gauges
     * @return bool false when the batch id was already applied (a redelivered
     *              job) — nothing is written in that case.
     */
    public function applyBatch(string $batchId, int $at, string $tenant, array $counters, array $gauges): bool;

    /** Sum of counter_sum over buckets whose start is in [$from, $to). */
    public function sum(string $metric, Resolution $resolution, int $from, int $to, ?string $tenant): int;

    /** @return list<Point> */
    public function series(string $metric, Resolution $resolution, int $from, int $to, ?string $tenant): array;

    public function latest(string $metric, ?string $tenant): ?float;

    /**
     * Rebuild the daily buckets for the days starting at each of `$dayStarts`
     * from the hourly ones. Replaces, never adds, so it is safe to repeat.
     *
     * @param list<int> $dayStarts
     */
    public function rebuildDays(array $dayStarts): int;

    /**
     * @return array{hourly: int, daily: int, batches: int}
     */
    public function prune(int $hourlyBefore, int $dailyBefore, int $batchesBefore): array;

    /** @return array{buckets: int, gauges: int, batches: int} */
    public function counts(): array;
}
