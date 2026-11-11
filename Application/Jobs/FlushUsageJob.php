<?php

declare(strict_types=1);

namespace Plugins\Usage\Application\Jobs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Usage\Application\Ports\UsageStore;
use Plugins\Usage\Domain\MetricName;

/**
 * The durable half of metering: applies one flushed batch.
 *
 * A store failure THROWS, so the worker's retry strategy spends the retries —
 * and because the store applies a batch atomically and exactly once per batch
 * id, a retry (or a queue that delivers twice) cannot count it twice.
 * A malformed payload is SKIPPED instead: retrying garbage three times and then
 * dead-lettering it would only hide that something is writing to this queue.
 */
final class FlushUsageJob implements JobContract
{
    public function __construct(
        private readonly UsageStore $store,
        private readonly ?LoggerPort $logger = null,
    ) {}

    public function handle(JobPayload $payload): JobResult
    {
        $data  = $payload->data();
        $batch = $data['batch'] ?? null;
        $at    = $data['at'] ?? null;

        if (!\is_string($batch) || preg_match('/^[a-f0-9]{32}$/', $batch) !== 1
            || !\is_int($at) || $at <= 0
            || !\is_string($data['tenant'] ?? null)) {
            return JobResult::skipped('Malformed usage payload.');
        }

        $counters = [];
        foreach ((array) ($data['counters'] ?? []) as $name => $value) {
            if (\is_string($name) && MetricName::isValid($name) && \is_int($value) && $value >= 0) {
                $counters[$name] = $value;
            }
        }

        $gauges = [];
        foreach ((array) ($data['gauges'] ?? []) as $name => $value) {
            if (\is_string($name) && MetricName::isValid($name)
                && (\is_int($value) || (\is_float($value) && is_finite($value)))) {
                $gauges[$name] = $value;
            }
        }

        if ($counters === [] && $gauges === []) {
            return JobResult::skipped('Usage payload carried no valid metric.');
        }

        $applied = $this->store->applyBatch($batch, $at, $data['tenant'], $counters, $gauges);

        return $applied
            ? JobResult::success(['counters' => \count($counters), 'gauges' => \count($gauges)])
            : JobResult::skipped('Batch already applied.');
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
        $this->logger?->error('Usage batch could not be applied and was dropped: {message}', [
            'message' => $e->getMessage(),
        ]);
    }
}
