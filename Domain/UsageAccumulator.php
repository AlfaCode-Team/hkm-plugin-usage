<?php

declare(strict_types=1);

namespace Plugins\Usage\Domain;

/**
 * What ONE request (or one job) measured, held in memory until it is flushed.
 *
 * ── REQUEST-SCOPED, AND THEREFORE NOT STATIC ─────────────────────────────────
 * This object is created per request and discarded with it. It must never be
 * reached through a static or held by a stage: pipeline stages are memoized per
 * worker, so anything a stage remembers is shared by every request that worker
 * serves — under OpenSwoole, by requests that are in flight at the same time.
 *
 * ── BOUNDED ──────────────────────────────────────────────────────────────────
 * A loop that builds a metric name from user input would otherwise grow this
 * array without limit inside one request. Past `$maxMetrics` distinct names the
 * newcomers are dropped and counted ({@see dropped()}), never stored.
 *
 * ── DRAIN, NOT READ ──────────────────────────────────────────────────────────
 * {@see drain()} returns the contents AND empties them, so a flush that is
 * called twice (the stage, then an explicit flush() from a service) cannot
 * count the same increments twice.
 */
final class UsageAccumulator
{
    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, int|float> */
    private array $gauges = [];

    private string $tenant = '';

    private int $dropped = 0;

    public function __construct(private readonly int $maxMetrics = 64) {}

    /**
     * Add to a counter. Counters only go up: a negative amount is a gauge being
     * misused, and accepting it would let a bug make a total go DOWN, which no
     * rate or growth calculation over it can survive.
     *
     * @throws \InvalidArgumentException
     */
    public function count(string $metric, int $by = 1): void
    {
        MetricName::assert($metric);

        if ($by < 0) {
            throw new \InvalidArgumentException("Counter [{$metric}] cannot decrease; use gauge() for a level.");
        }

        if (!isset($this->counters[$metric]) && !$this->hasRoom()) {
            $this->dropped++;

            return;
        }

        $this->counters[$metric] = ($this->counters[$metric] ?? 0) + $by;
    }

    /**
     * Record a level. The last call in the request wins.
     *
     * @throws \InvalidArgumentException
     */
    public function gauge(string $metric, int|float $value): void
    {
        MetricName::assert($metric);

        if (\is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException("Gauge [{$metric}] must be a finite number.");
        }

        if (!isset($this->gauges[$metric]) && !$this->hasRoom()) {
            $this->dropped++;

            return;
        }

        $this->gauges[$metric] = $value;
    }

    /**
     * Attribute this request's measurements to a tenant. Attribution only — the
     * id comes from an Identity, which is a HINT, and nothing here authorises
     * anything.
     */
    public function forTenant(string $tenantId): void
    {
        $this->tenant = substr($tenantId, 0, 64);
    }

    public function tenant(): string
    {
        return $this->tenant;
    }

    public function isEmpty(): bool
    {
        return $this->counters === [] && $this->gauges === [];
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * @return array{tenant: string, counters: array<string, int>, gauges: array<string, int|float>}
     */
    public function drain(): array
    {
        $out = ['tenant' => $this->tenant, 'counters' => $this->counters, 'gauges' => $this->gauges];

        $this->counters = [];
        $this->gauges   = [];

        return $out;
    }

    private function hasRoom(): bool
    {
        return \count($this->counters) + \count($this->gauges) < $this->maxMetrics;
    }
}
