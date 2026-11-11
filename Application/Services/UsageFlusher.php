<?php

declare(strict_types=1);

namespace Plugins\Usage\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use Plugins\Usage\Domain\UsageAccumulator;

/**
 * Turns an accumulator into ONE queue message.
 *
 * THIS CLASS HAS NO DATABASE DEPENDENCY, ON PURPOSE. It is what runs on the
 * request path, and the guarantee that the request path never performs the
 * durable write is enforced structurally: there is nothing here that could.
 * `UsageRequestPathTest` pins both the behaviour and this constructor.
 */
final class UsageFlusher
{
    /** The job NAME, as declared in module.json `jobs[]` — not a class. */
    public const JOB = 'usage.flush';

    public function __construct(
        private readonly ?QueuePort $queue,
        private readonly ClockPort $clock,
        private readonly string $queueName = 'usage',
        private readonly ?LoggerPort $logger = null,
    ) {}

    /**
     * Never throws. A queue that is down must not turn every response into a 500.
     */
    public function flush(UsageAccumulator $accumulator): bool
    {
        if ($accumulator->isEmpty()) {
            return false;
        }

        // Drain FIRST, so that even if the push fails the same increments are
        // not retried by a second flush in this request.
        $batch = $accumulator->drain();

        if ($this->queue === null) {
            // Dropped, not written inline. There is no fallback path that
            // touches the database, because that is the cost this design exists
            // to avoid. `usage:status` reports the missing queue.
            return false;
        }

        try {
            $this->queue->push(self::JOB, [
                // Idempotency key: a job delivered twice must not count twice.
                'batch'    => bin2hex(random_bytes(16)),
                'at'       => $this->clock->timestamp(),
                'tenant'   => $batch['tenant'],
                'counters' => $batch['counters'],
                'gauges'   => $batch['gauges'],
            ], $this->queueName);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Usage batch could not be queued and was dropped: {message}', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
