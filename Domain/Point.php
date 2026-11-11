<?php

declare(strict_types=1);

namespace Plugins\Usage\Domain;

/** One bucket of a series. `counter` is the sum in the bucket; `gauge` the last level in it, or null. */
final readonly class Point
{
    public function __construct(
        public int $bucket,
        public int $counter,
        public int|float|null $gauge,
    ) {}
}
