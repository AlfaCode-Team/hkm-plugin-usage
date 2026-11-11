<?php

declare(strict_types=1);

namespace Plugins\Usage\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use Plugins\Usage\Application\Services\UsageFlusher;
use Plugins\Usage\Domain\UsageAccumulator;
use Plugins\Usage\Infrastructure\Config\UsageConfig;

/**
 * Global `after.execute` hook (priority 90, the observability band).
 *
 * ── THIS OBJECT HOLDS NOTHING REQUEST-SCOPED ────────────────────────────────
 * Stages are memoized per worker. The accumulator is therefore a LOCAL of
 * handle(), shared with the rest of the request through the container
 * (where the module registered it) or the `usage` request attribute — never
 * through a property of this class and never through a static.
 *
 * ── ENQUEUE, NEVER WRITE ────────────────────────────────────────────────────
 * The only thing this stage does after the response exists is one queue push,
 * through {@see UsageFlusher}, which has no database dependency at all.
 *
 * It sits IN FRONT of ExecuteStage in the onion (see HttpPipeline), so the
 * controller's Response comes back through it, which is how the status is known.
 * An exception is flushed too — a 500 is exactly what `http.errors` counts —
 * and then rethrown for ErrorStage, which wraps everything.
 */
final class UsageFlushStage implements HttpStageContract
{
    public function __construct(private readonly ?UsageConfig $config = null) {}

    public function handle(Request $request, callable $next): Response
    {
        $container = $request->container();
        $config    = $this->config ?? UsageConfig::fromEnv();

        $accumulator = $container !== null && $container->has(UsageAccumulator::class)
            ? $container->make(UsageAccumulator::class)
            : new UsageAccumulator($config->maxMetrics);

        $accumulator->forTenant((string) ($request->identity()?->tenantId ?? ''));

        // Objects are shared across Request clones, so a controller reading
        // this attribute and a stage that cloned the request see one accumulator.
        $request = $request->withAttribute('usage', $accumulator);

        $status = 500;

        try {
            $response = $next($request);
            $status   = $response->status();

            if ($config->trackRequests) {
                $length = $response->headers()['Content-Length'] ?? null;
                if ($length !== null && ctype_digit($length)) {
                    $accumulator->count('http.bytes_out', (int) $length);
                }
            }

            return $response;
        } finally {
            // Runs for a returned Response AND for a Throwable on its way to ErrorStage.
            if ($config->trackRequests) {
                $accumulator->count('http.requests');
                if ($status >= 500) {
                    $accumulator->count('http.errors');
                }
            }

            $this->flusher($container, $config)->flush($accumulator);
        }
    }

    private function flusher(?\AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer $c, UsageConfig $config): UsageFlusher
    {
        return new UsageFlusher(
            queue:     $c !== null && $c->has(QueuePort::class) ? $c->make(QueuePort::class) : null,
            clock:     $c !== null && $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock(),
            queueName: $config->queue,
            logger:    $c !== null && $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
        );
    }
}
