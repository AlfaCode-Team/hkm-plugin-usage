<?php

declare(strict_types=1);

namespace Plugins\Usage\Domain;

/**
 * A counter compared with itself one window earlier.
 *
 * `ratio` is null when there was nothing in the previous window: a change from
 * zero is undefined, not infinite, and a rule that read "infinity" as "above
 * every threshold" would page on the first request of a brand-new metric.
 */
final readonly class Growth
{
    public function __construct(
        public int $current,
        public int $previous,
    ) {}

    public function delta(): int
    {
        return $this->current - $this->previous;
    }

    /** (current - previous) / previous, or null when previous is 0. */
    public function ratio(): ?float
    {
        return $this->previous === 0 ? null : ($this->current - $this->previous) / $this->previous;
    }
}
