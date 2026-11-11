<?php

declare(strict_types=1);

namespace Plugins\Usage;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use Plugins\Usage\API\Contracts\UsageContract;
use Plugins\Usage\Application\Jobs\FlushUsageJob;
use Plugins\Usage\Application\Ports\UsageStore;
use Plugins\Usage\Application\Services\UsageFlusher;
use Plugins\Usage\Application\Services\UsageService;
use Plugins\Usage\Domain\UsageAccumulator;
use Plugins\Usage\Infrastructure\Cli\UsageRollupCommand;
use Plugins\Usage\Infrastructure\Cli\UsageShowCommand;
use Plugins\Usage\Infrastructure\Config\UsageConfig;
use Plugins\Usage\Infrastructure\Http\UsageFlushStage;
use Plugins\Usage\Infrastructure\Persistence\UsageRepository;

/**
 * Usage — wires the 'usage.metering' domain.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'usage.metering';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [UsageContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(UsageConfig::class, static fn (): UsageConfig => UsageConfig::fromEnv());

        // ONE accumulator per request: a singleton in a request-scoped container
        // is discarded with it. Public, because UsageFlushStage resolves it from
        // outside this module's scope to drain it at after.execute.
        $container->singleton(
            UsageAccumulator::class,
            static fn (ModuleContainer $c): UsageAccumulator => new UsageAccumulator($c->make(UsageConfig::class)->maxMetrics),
        );

        // Internal and SHARED: the Database plugin's port is transient (each
        // make() is a new connection), and applyBatch()'s transaction only holds
        // when every statement runs on one instance.
        $container->bindInternal(
            UsageStore::class,
            static fn (ModuleContainer $c): UsageStore => new UsageRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            UsageFlusher::class,
            static fn (ModuleContainer $c): UsageFlusher => new UsageFlusher(
                queue:     $c->has(QueuePort::class) ? $c->make(QueuePort::class) : null,
                clock:     $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock(),
                queueName: $c->make(UsageConfig::class)->queue,
                logger:    $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            ),
        );

        $container->bind(
            UsageContract::class,
            static fn (ModuleContainer $c): UsageService => new UsageService(
                store:       $c->make(UsageStore::class),
                flusher:     $c->make(UsageFlusher::class),
                accumulator: $c->make(UsageAccumulator::class),
                clock:       $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock(),
                config:      $c->make(UsageConfig::class),
            ),
        );

        // Declared in module.json `jobs[]` as "usage.flush".
        //
        // PUBLIC, not bindInternal(): WorkerLoop resolves the handler with
        // `$container->make($class)` from OUTSIDE the module's scope, and an
        // internal binding throws ScopeViolationException there — the job is then
        // released and retried forever. Found by RealKernelTest.
        $container->bind(
            FlushUsageJob::class,
            static fn (ModuleContainer $c): FlushUsageJob => new FlushUsageJob(
                $c->make(UsageStore::class),
                $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // A GLOBAL hook, not a route filter: metering must see every request,
        // and it must be registered exactly one way (both would count twice).
        // 90 is the observability band.
        $http->hook('after.execute', UsageFlushStage::class, priority: 90);

        // The jobs[] entry in module.json only compiles job-manifest.php (queue,
        // retry, timeout, owning module). The worker's NAME -> CLASS registry is
        // WorkerPipeline::job(), populated here and nowhere else: without this
        // line the worker finds no class for "usage.flush", reports the job as
        // skipped, and ACKS it — metering silently records nothing, and every
        // unit test passes because they all construct the job by hand. Found by
        // RealKernelTest; do not remove it.
        $worker->job('usage.flush', FlushUsageJob::class);

        $cli->defer(static function (CliPipeline $cli): void {
            $core  = $cli->container();
            $clock = $core->has(ClockPort::class) ? $core->make(ClockPort::class) : new SystemClock();

            $usage = self::forCli($core, $clock);

            $cli->command(new UsageRollupCommand($usage));
            $cli->command(new UsageShowCommand($usage, $clock));
        });
    }

    private static function forCli(CoreContainer $core, ClockPort $clock): UsageService
    {
        $config = UsageConfig::fromEnv();

        return new UsageService(
            store:       new UsageRepository($core->make(DatabasePort::class)),
            flusher:     new UsageFlusher(
                queue:     $core->has(QueuePort::class) ? $core->make(QueuePort::class) : null,
                clock:     $clock,
                queueName: $config->queue,
                logger:    $core->has(LoggerPort::class) ? $core->make(LoggerPort::class) : null,
            ),
            accumulator: new UsageAccumulator($config->maxMetrics),
            clock:       $clock,
            config:      $config,
        );
    }
}
