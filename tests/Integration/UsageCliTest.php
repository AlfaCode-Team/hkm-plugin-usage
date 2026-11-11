<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Integration;

use AlfacodeTeam\Ground\Fakes\FakeQueue;
use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\Ground\Fakes\MigratedSqlite;
use AlfacodeTeam\PhpIoCli\BufferIO;
use PHPUnit\Framework\TestCase;
use Plugins\Usage\Application\Services\UsageFlusher;
use Plugins\Usage\Application\Services\UsageService;
use Plugins\Usage\Domain\UsageAccumulator;
use Plugins\Usage\Infrastructure\Cli\UsageRollupCommand;
use Plugins\Usage\Infrastructure\Cli\UsageShowCommand;
use Plugins\Usage\Infrastructure\Config\UsageConfig;
use Plugins\Usage\Infrastructure\Persistence\UsageRepository;

/** Both commands, actually executed, against a migrated SQLite database. */
final class UsageCliTest extends TestCase
{
    private MigratedSqlite $db;
    private UsageService $service;
    private FrozenClock $clock;
    private bool $ready = false;

    protected function setUp(): void
    {
        $this->db    = MigratedSqlite::migrated(\dirname(__DIR__, 2) . '/database/migrations', 'usage-cli');
        $this->ready = true;
        $this->clock = new FrozenClock('2026-03-05 13:45:10');
        $repo        = new UsageRepository($this->db);

        $repo->applyBatch(str_pad('a', 32, '0'), $this->clock->timestamp(), 't1', ['http.requests' => 12], ['queue.depth' => 3]);

        $this->service = new UsageService(
            $repo,
            new UsageFlusher(new FakeQueue(), $this->clock),
            new UsageAccumulator(),
            $this->clock,
            new UsageConfig(),
        );
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->db->discard();
        }
    }

    public function test_show_prints_the_status_line_and_a_metric(): void
    {
        $io   = new BufferIO();
        $code = (new UsageShowCommand($this->service, $this->clock))->execute(['http.requests', '--hours=3'], $io);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('usage.metering', $io->getOutput());
        $this->assertStringContainsString('2026-03-05 13:00', $io->getOutput());
        $this->assertStringContainsString('12', $io->getOutput());
    }

    public function test_show_without_a_metric_prints_only_status(): void
    {
        $io = new BufferIO();

        $this->assertSame(0, (new UsageShowCommand($this->service, $this->clock))->execute([], $io));
        $this->assertStringNotContainsString('Metric', $io->getOutput());
    }

    public function test_show_rejects_a_bad_metric_name_with_a_message_and_a_failure_code(): void
    {
        $io   = new BufferIO();
        $code = (new UsageShowCommand($this->service, $this->clock))->execute(['Bad Name'], $io);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Invalid metric name', $io->getOutput());
    }

    public function test_rollup_runs_and_is_quiet_when_idle_on_request(): void
    {
        $io = new BufferIO();
        $this->assertSame(0, (new UsageRollupCommand($this->service))->execute([], $io));
        $this->assertStringContainsString('Rebuilt', $io->getOutput());

        // Same minute, nothing new except the rebuilt day row — still rebuilt, so not idle.
        // A genuinely idle store:
        $empty = MigratedSqlite::migrated(\dirname(__DIR__, 2) . '/database/migrations', 'usage-cli-empty');
        try {
            $service = new UsageService(new UsageRepository($empty), new UsageFlusher(null, $this->clock), new UsageAccumulator(), $this->clock, new UsageConfig());
            $quiet   = new BufferIO();

            $this->assertSame(0, (new UsageRollupCommand($service))->execute(['--quiet-when-idle'], $quiet));
            $this->assertSame('', trim($quiet->getOutput()));
        } finally {
            $empty->discard();
        }
    }
}
