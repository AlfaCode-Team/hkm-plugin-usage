<?php

declare(strict_types=1);

namespace Plugins\Usage\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\Usage\API\Contracts\UsageContract;
use Plugins\Usage\Application\Ports\UsageStore;
use Plugins\Usage\Domain\Growth;
use Plugins\Usage\Domain\MetricName;
use Plugins\Usage\Domain\Resolution;
use Plugins\Usage\Domain\RollupReport;
use Plugins\Usage\Domain\UsageAccumulator;
use Plugins\Usage\Infrastructure\Config\UsageConfig;

/**
 * No transaction/collector/event-bus here, deliberately. The mandatory service
 * shape exists to keep domain events and writes consistent; metering emits no
 * events and every write it performs happens in {@see UsageStore::applyBatch()},
 * which is one atomic transaction of its own, from a worker.
 */
final class UsageService implements UsageContract
{
    public function __construct(
        private readonly UsageStore $store,
        private readonly UsageFlusher $flusher,
        private readonly UsageAccumulator $accumulator,
        private readonly ClockPort $clock,
        private readonly UsageConfig $config,
    ) {}

    public function count(string $metric, int $by = 1): void
    {
        $this->accumulator->count($metric, $by);
    }

    public function gauge(string $metric, int|float $value): void
    {
        $this->accumulator->gauge($metric, $value);
    }

    public function flush(): bool
    {
        return $this->flusher->flush($this->accumulator);
    }

    public function sum(string $metric, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $tenant = null): int
    {
        MetricName::assert($metric);

        $resolution = $this->resolutionFor($from->getTimestamp());

        return $this->store->sum(
            $metric,
            $resolution,
            $resolution->floor($from->getTimestamp()),
            $to->getTimestamp(),
            $tenant,
        );
    }

    public function latest(string $metric, ?string $tenant = null): ?float
    {
        MetricName::assert($metric);

        return $this->store->latest($metric, $tenant);
    }

    public function series(
        string $metric,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        Resolution $resolution = Resolution::Hour,
        ?string $tenant = null,
    ): array {
        MetricName::assert($metric);

        return $this->store->series(
            $metric,
            $resolution,
            $resolution->floor($from->getTimestamp()),
            $to->getTimestamp(),
            $tenant,
        );
    }

    public function growth(string $metric, int $windowSeconds, ?string $tenant = null): Growth
    {
        MetricName::assert($metric);

        if ($windowSeconds < 3600 || $windowSeconds % 3600 !== 0) {
            throw new \InvalidArgumentException('A growth window must be a positive multiple of 3600 seconds.');
        }

        $now = $this->clock->timestamp();
        $to  = Resolution::Hour->floor($now) + 3600;
        $from = $to - $windowSeconds;
        $prev = $from - $windowSeconds;

        // Growth reads hourly rows only: a window that is not day-aligned cannot
        // be answered from daily ones without being wrong. Refusing is better
        // than a ratio computed over a half-pruned previous window, which would
        // read as growth that never happened.
        if ($prev < $now - $this->config->hourlyRetentionDays * 86400) {
            throw new \InvalidArgumentException(
                'Two windows of ' . $windowSeconds . 's do not fit in the hourly retention of '
                . $this->config->hourlyRetentionDays . ' days.'
            );
        }

        return new Growth(
            current:  $this->store->sum($metric, Resolution::Hour, $from, $to, $tenant),
            previous: $this->store->sum($metric, Resolution::Hour, $prev, $from, $tenant),
        );
    }

    public function rollup(): RollupReport
    {
        $now         = $this->clock->timestamp();
        $hourlyStart = $now - $this->config->hourlyRetentionDays * 86400;

        // First day whose hourly rows are ALL still present. A day that is
        // partly pruned must not be rebuilt: it would replace a complete total
        // with a partial one.
        $firstDay = Resolution::Day->floor($hourlyStart);
        if ($firstDay < $hourlyStart) {
            $firstDay += 86400;
        }

        $days = [];
        for ($d = $firstDay; $d <= Resolution::Day->floor($now); $d += 86400) {
            $days[] = $d;
        }

        // Order is defensive, not load-bearing: the rebuilt days start at or after
        // `$hourlyStart`, and prune() only deletes hours before it, so the two
        // never touch the same rows (a revert check swapped them and nothing
        // failed). It stays rebuild-first so that if the alignment above is ever
        // loosened, nothing is deleted that has not been folded.
        $rebuilt = $this->store->rebuildDays($days);

        $pruned = $this->store->prune(
            hourlyBefore:  $hourlyStart,
            dailyBefore:   $now - $this->config->dailyRetentionDays * 86400,
            batchesBefore: $now - $this->config->batchRetentionHours * 3600,
        );

        return new RollupReport($rebuilt, $pruned['hourly'], $pruned['daily'], $pruned['batches']);
    }

    public function describe(): string
    {
        $counts = $this->store->counts();

        return sprintf(
            'usage.metering — queue "%s", request tracking %s, retention %dd hourly / %dd daily, %d buckets, %d gauges',
            $this->config->queue,
            $this->config->trackRequests ? 'on' : 'off',
            $this->config->hourlyRetentionDays,
            $this->config->dailyRetentionDays,
            $counts['buckets'],
            $counts['gauges'],
        );
    }

    /** Hourly rows reach back `hourlyRetentionDays`; older questions go to daily ones. */
    private function resolutionFor(int $from): Resolution
    {
        return $from < $this->clock->timestamp() - $this->config->hourlyRetentionDays * 86400
            ? Resolution::Day
            : Resolution::Hour;
    }
}
