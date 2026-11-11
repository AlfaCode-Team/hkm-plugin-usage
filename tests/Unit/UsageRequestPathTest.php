<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Unit;

use AlfacodeTeam\Ground\Fakes\FakeLogger;
use AlfacodeTeam\Ground\Fakes\FakeQueue;
use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\TestCase;
use Plugins\Usage\API\Contracts\UsageContract;
use Plugins\Usage\Application\Services\UsageFlusher;
use Plugins\Usage\Domain\UsageAccumulator;
use Plugins\Usage\Infrastructure\Config\UsageConfig;
use Plugins\Usage\Infrastructure\Http\UsageFlushStage;
use Plugins\Usage\Provider;
use Tests\Plugins\Usage\Support\DownQueue;
use Tests\Plugins\Usage\Support\SpyDatabase;

/**
 * THE pin of this plugin: the request path enqueues and never writes.
 *
 * Appwrite runs its metering queue at 8 coroutines because metering fires on
 * every request; a synchronous write on this path taxes every response. These
 * tests bind a database that counts its calls, drive a whole request through the
 * real stage and the real Provider wiring, and assert the count is zero while
 * the queue holds exactly one message.
 */
final class UsageRequestPathTest extends TestCase
{
    private SpyDatabase $db;
    private FakeQueue $queue;
    private FakeLogger $logger;
    private ModuleContainer $container;

    protected function setUp(): void
    {
        $this->db     = new SpyDatabase();
        $this->queue  = new FakeQueue();
        $this->logger = new FakeLogger();

        $core = new CoreContainer();
        $core->instance(DatabasePort::class, $this->db);
        $core->instance(QueuePort::class, $this->queue);
        $core->instance(ClockPort::class, new FrozenClock('2026-03-05 13:45:10'));
        $core->instance(LoggerPort::class, $this->logger);

        $this->container = new ModuleContainer($core);
        $this->container->setScope('usage.metering');
        (new Provider())->register($this->container);
        $this->container->setScope('');
    }

    private function request(?Identity $identity = null): Request
    {
        $request = Request::create('/orders', 'POST')->withContainer($this->container);

        return $identity === null ? $request : $request->withIdentity($identity);
    }

    private function stage(): UsageFlushStage
    {
        return new UsageFlushStage(new UsageConfig());
    }

    public function test_a_whole_request_enqueues_one_message_and_performs_no_database_call(): void
    {
        $response = $this->stage()->handle(
            $this->request(Identity::asUser('u1', 'tenant-a')),
            function (Request $r): Response {
                // A service metering mid-request, through the real contract.
                $usage = $this->container->make(UsageContract::class);
                $usage->count('orders.created');
                $usage->gauge('cart.size', 3);

                return Response::json(['ok' => true], 201);
            },
        );

        $this->assertSame(201, $response->status());
        $this->assertSame(0, $this->db->calls, 'the request path touched the database: ' . implode(',', $this->db->log));

        $this->assertCount(1, $this->queue->pushed);
        $push = $this->queue->pushed[0];
        $this->assertSame(UsageFlusher::JOB, $push['job']);
        $this->assertSame('usage', $push['queue']);
        $this->assertSame('tenant-a', $push['payload']['tenant']);
        $this->assertSame(['orders.created' => 1, 'http.requests' => 1], $push['payload']['counters']);
        $this->assertSame(['cart.size' => 3], $push['payload']['gauges']);
        $this->assertSame(gmmktime(13, 45, 10, 3, 5, 2026), $push['payload']['at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $push['payload']['batch']);
    }

    public function test_the_hot_path_classes_cannot_reach_the_database_by_construction(): void
    {
        $forbidden = ['DatabasePort', 'UsageStore', 'UsageRepository', 'UsageService'];

        foreach ([UsageFlusher::class, UsageFlushStage::class, UsageAccumulator::class] as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();

            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = (string) $parameter->getType();
                foreach ($forbidden as $name) {
                    $this->assertStringNotContainsString($name, $type, "{$class}::\${$parameter->getName()} could write durably");
                }
            }
        }
    }

    public function test_nothing_request_scoped_is_static(): void
    {
        foreach ([UsageFlushStage::class, UsageFlusher::class, UsageAccumulator::class] as $class) {
            $this->assertSame([], (new \ReflectionClass($class))->getStaticProperties(), "{$class} has a static property");
        }
    }

    public function test_the_memoized_stage_does_not_carry_one_request_into_the_next(): void
    {
        $stage = $this->stage(); // one instance, as the pipeline memoizes it

        $stage->handle($this->request(), function (Request $r): Response {
            $this->container->make(UsageContract::class)->count('first.only', 7);

            return Response::json([]);
        });

        // A second request gets a FRESH container, as a worker would build.
        $core2 = new CoreContainer();
        $core2->instance(QueuePort::class, $this->queue);
        $c2 = new ModuleContainer($core2);
        $c2->setScope('usage.metering');
        $core2->instance(DatabasePort::class, $this->db);
        (new Provider())->register($c2);
        $c2->setScope('');

        $stage->handle(Request::create('/b')->withContainer($c2), static fn (Request $r): Response => Response::json([]));

        $this->assertCount(2, $this->queue->pushed);
        $this->assertSame(['first.only' => 7, 'http.requests' => 1], $this->queue->pushed[0]['payload']['counters']);
        $this->assertSame(['http.requests' => 1], $this->queue->pushed[1]['payload']['counters']);
    }

    public function test_a_server_error_is_counted_and_an_exception_is_flushed_then_rethrown(): void
    {
        $this->stage()->handle($this->request(), static fn (Request $r): Response => Response::json([], 503));

        $this->assertSame(['http.requests' => 1, 'http.errors' => 1], $this->queue->pushed[0]['payload']['counters']);

        $threw = false;
        try {
            $this->stage()->handle($this->request(), static function (Request $r): Response {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'ErrorStage must still see the exception');
        $this->assertCount(2, $this->queue->pushed);
        $this->assertSame(1, $this->queue->pushed[1]['payload']['counters']['http.errors']);
        $this->assertSame(0, $this->db->calls);
    }

    public function test_a_queue_that_is_down_never_fails_the_response(): void
    {
        $core = new CoreContainer();
        $core->instance(QueuePort::class, $down = new DownQueue());
        $core->instance(LoggerPort::class, $this->logger);
        $core->instance(DatabasePort::class, $this->db);
        $c = new ModuleContainer($core);

        $response = $this->stage()->handle(
            Request::create('/x')->withContainer($c),
            static fn (Request $r): Response => Response::json(['ok' => 1]),
        );

        $this->assertSame(200, $response->status());
        $this->assertTrue($this->logger->logged('warning', 'could not be queued'));
        $this->assertSame(1, $down->attempts);
        $this->assertSame(0, $this->db->calls, 'a dead queue must not fall back to an inline write');
    }

    public function test_with_no_queue_bound_the_batch_is_dropped_and_still_nothing_is_written(): void
    {
        $core = new CoreContainer();
        $core->instance(DatabasePort::class, $this->db);
        $c = new ModuleContainer($core);

        $response = $this->stage()->handle(
            Request::create('/x')->withContainer($c),
            static fn (Request $r): Response => Response::json([]),
        );

        $this->assertSame(200, $response->status());
        $this->assertSame(0, $this->db->calls);
    }

    public function test_request_tracking_can_be_turned_off_and_an_empty_request_pushes_nothing(): void
    {
        $stage = new UsageFlushStage(new UsageConfig(trackRequests: false));

        $stage->handle($this->request(), static fn (Request $r): Response => Response::json([]));

        $this->assertSame([], $this->queue->pushed);
    }

    public function test_the_accumulator_is_shared_with_the_controller_through_the_attribute(): void
    {
        $this->stage()->handle($this->request(), static function (Request $r): Response {
            $r->attribute('usage')->count('from.attribute', 2);

            return Response::json([]);
        });

        $this->assertSame(2, $this->queue->pushed[0]['payload']['counters']['from.attribute']);
    }
}
