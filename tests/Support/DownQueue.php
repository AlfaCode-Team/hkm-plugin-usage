<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

/** A QueuePort whose broker is unreachable. Counts the pushes it was asked for. */
final class DownQueue implements QueuePort
{
    public int $attempts = 0;

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
    {
        $this->attempts++;

        throw new \RuntimeException('redis is down');
    }

    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string { return $this->push($jobClass, $payload, $queue, $seconds); }
    public function size(string $queue = 'default'): int { return 0; }
    public function pop(string $queue = 'default'): ?JobPayload { return null; }
    public function ack(JobPayload $payload): void {}
    public function release(JobPayload $payload, int $delay = 0): void {}
    public function fail(JobPayload $payload, ?\Throwable $reason = null): void {}
}
