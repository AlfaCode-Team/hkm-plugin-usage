<?php

declare(strict_types=1);

namespace Plugins\Usage\Domain;

final readonly class RollupReport
{
    public function __construct(
        public int $daysRebuilt = 0,
        public int $hourlyPruned = 0,
        public int $dailyPruned = 0,
        public int $batchesPruned = 0,
    ) {}
}
