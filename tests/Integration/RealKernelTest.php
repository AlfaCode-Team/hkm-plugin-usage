<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Integration;

use AlfacodeTeam\Ground\PluginGround;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use PHPUnit\Framework\TestCase;
use Plugins\Usage\Provider;
use Tests\Plugins\Usage\Support\SpyDatabase;

/**
 * The plugin booted by the REAL kernel (through Ground): real BootPipeline,
 * real module.json compilation, real HttpPipeline with the real after.execute
 * hook. The unit tests drive the stage by hand; this proves boot() actually
 * registers it and the pipeline actually calls it.
 */
final class RealKernelTest extends TestCase
{
    private ?\AlfacodeTeam\Ground\BootedGround $ground = null;
    private SpyDatabase $db;

    protected function setUp(): void
    {
        $this->db = new SpyDatabase();

        $this->ground = PluginGround::for(Provider::class)
            ->port(DatabasePort::class, $this->db)
            ->routes([
                ['method' => 'GET', 'path' => '/probe',      'handler' => 'Tests\\Plugins\\Usage\\Support\\ProbeController@ok'],
                ['method' => 'GET', 'path' => '/probe/boom', 'handler' => 'Tests\\Plugins\\Usage\\Support\\ProbeController@boom'],
                // Declarative route labels: the kernel's label stage counts `metric`
                // names on the same accumulator, through the `usage` attribute.
                ['method' => 'GET', 'path' => '/probe/labelled', 'handler' => 'Tests\\Plugins\\Usage\\Support\\ProbeController@ok',
                    'metric' => ['probe.labelled', 'probe.labelled.again']],
                ['method' => 'GET', 'path' => '/probe/labelled-boom', 'handler' => 'Tests\\Plugins\\Usage\\Support\\ProbeController@boom',
                    'metric' => 'probe.never'],
            ])
            ->boot();
    }

    protected function tearDown(): void
    {
        $this->ground?->destroy();
    }

    private function g(): \AlfacodeTeam\Ground\BootedGround
    {
        return $this->ground ?? throw new \LogicException('not booted');
    }

    public function test_a_request_through_the_real_pipeline_enqueues_one_usage_batch_and_writes_nothing(): void
    {
        $response = $this->g()->get('/probe');

        $this->assertSame(200, $response->status());

        $pushed = $this->g()->queue()->pushed;
        $this->assertCount(1, $pushed);
        $this->assertSame('usage.flush', $pushed[0]['job']);
        $this->assertSame(1, $pushed[0]['payload']['counters']['http.requests']);
        $this->assertSame(1, $pushed[0]['payload']['counters']['probe.hits'], 'the controller reached the accumulator through the request attribute');
        $this->assertSame(0, $this->db->calls, 'a durable write happened on the request path: ' . implode(',', $this->db->log));
    }

    public function test_a_request_that_never_reaches_execution_is_not_metered_which_is_a_stated_limit(): void
    {
        // after.execute sits in front of ExecuteStage, so a 404 from ResolveStage
        // (and a denial from SecurityStage or a route filter) never reaches it.
        // README "What is not counted". If this ever starts failing, the kernel's
        // pipeline order changed and the README claim must be revisited.
        $this->assertSame(404, $this->g()->get('/no-such-route')->status());

        $this->assertSame([], $this->g()->queue()->pushed);
    }

    public function test_a_route_metric_label_is_counted_on_the_same_accumulator_with_no_handler_code(): void
    {
        $this->assertSame(200, $this->g()->get('/probe/labelled')->status());

        $counters = $this->g()->queue()->pushed[0]['payload']['counters'];
        $this->assertSame(1, $counters['probe.labelled']);
        $this->assertSame(1, $counters['probe.labelled.again']);
        $this->assertSame(1, $counters['http.requests'], 'one request, one batch — the label added to it rather than flushing separately');
        $this->assertCount(1, $this->g()->queue()->pushed);
    }

    public function test_a_route_metric_label_does_not_count_a_response_that_was_an_error(): void
    {
        $this->g()->get('/probe/labelled-boom');

        $counters = $this->g()->queue()->pushed[0]['payload']['counters'];
        $this->assertArrayNotHasKey('probe.never', $counters, 'a 503 means the thing the label names did not happen');
        $this->assertSame(1, $counters['http.errors'], 'the failure is still measured, by the plugin\'s own request counters');
    }

    public function test_a_503_from_a_controller_is_counted_as_an_error(): void
    {
        $this->g()->get('/probe/boom');

        $this->assertSame(1, $this->g()->queue()->pushed[0]['payload']['counters']['http.errors']);
    }

    public function test_the_flush_job_and_both_commands_are_in_the_compiled_manifests(): void
    {
        $this->assertTrue($this->g()->hasJob('usage.flush'));
        $this->assertTrue($this->g()->hasCommand('usage:rollup'));
        $this->assertTrue($this->g()->hasCommand('usage:show'));
    }

    public function test_the_worker_applies_the_queued_batch_to_the_database_in_the_real_kernel(): void
    {
        $sqlite = \AlfacodeTeam\Ground\Fakes\MigratedSqlite::migrated(\dirname(__DIR__, 2) . '/database/migrations', 'usage-kernel');

        $ground = PluginGround::for(Provider::class)
            ->port(DatabasePort::class, $sqlite)
            ->routes([['method' => 'GET', 'path' => '/probe', 'handler' => 'Tests\\Plugins\\Usage\\Support\\ProbeController@ok']])
            ->boot();

        try {
            $ground->get('/probe');
            $this->assertSame(0, (int) $sqlite->queryOne('SELECT COUNT(*) AS n FROM usage_buckets')['n'], 'nothing is written until the worker runs');

            $ground->work('usage', 1);

            $row = $sqlite->queryOne("SELECT counter_sum FROM usage_buckets WHERE metric = 'http.requests'");
            $this->assertSame(1, (int) ($row['counter_sum'] ?? 0));
            $this->assertSame(1, (int) $sqlite->queryOne("SELECT counter_sum FROM usage_buckets WHERE metric = 'probe.hits'")['counter_sum']);
        } finally {
            $ground->destroy();
            $sqlite->discard();
        }
    }
}
