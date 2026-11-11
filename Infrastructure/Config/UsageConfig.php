<?php

declare(strict_types=1);

namespace Plugins\Usage\Infrastructure\Config;

final readonly class UsageConfig
{
    public function __construct(
        public string $queue = 'usage',
        /** Count http.requests / http.errors / http.bytes_out on every request. */
        public bool $trackRequests = true,
        public int $maxMetrics = 64,
        public int $hourlyRetentionDays = 14,
        public int $dailyRetentionDays = 400,
        /**
         * How long a flushed batch id is remembered. It must outlive any
         * redelivery of the same job, or a late retry is counted twice.
         */
        public int $batchRetentionHours = 48,
    ) {}

    public static function fromEnv(): self
    {
        $track = env('USAGE_TRACK_REQUESTS');

        return new self(
            queue:               trim((string) env('USAGE_QUEUE')) ?: 'usage',
            trackRequests:       $track === null || $track === '' ? true : filter_var($track, FILTER_VALIDATE_BOOL),
            maxMetrics:          max(1, (int) (env('USAGE_MAX_METRICS') ?: 64)),
            hourlyRetentionDays: max(2, (int) (env('USAGE_HOURLY_RETENTION_DAYS') ?: 14)),
            dailyRetentionDays:  max(2, (int) (env('USAGE_DAILY_RETENTION_DAYS') ?: 400)),
            batchRetentionHours: max(1, (int) (env('USAGE_BATCH_RETENTION_HOURS') ?: 48)),
        );
    }
}
