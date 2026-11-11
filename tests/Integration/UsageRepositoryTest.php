<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Integration;

use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\Ground\Fakes\FakeQueue;
use AlfacodeTeam\Ground\Fakes\MigratedSqlite;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use PHPUnit\Framework\TestCase;
use Plugins\Usage\Application\Jobs\FlushUsageJob;
use Plugins\Usage\Application\Services\UsageFlusher;
use Plugins\Usage\Application\Services\UsageService;
use Plugins\Usage\Domain\Resolution;
use Plugins\Usage\Domain\UsageAccumulator;
use Plugins\Usage\Infrastructure\Config\UsageConfig;
use Plugins\Usage\Infrastructure\Persistence\UsageRepository;
use Tests\Plugins\Usage\Support\FlakyDatabase;

/**
 * The real SQL, against a migrated SQLite database. Nothing here has run on
 * MySQL or PostgreSQL.
 */
final class UsageRepositoryTest extends TestCase
{
    private MigratedSqlite $db;
    private UsageRepository $repo;
    private FrozenClock $clock;
    private UsageService $service;
    private bool $ready = false;

    private const T0 = '2026-03-05 13:45:10';

    protected function setUp(): void
    {
        $this->db    = MigratedSqlite::migrated(\dirname(__DIR__, 2) . '/database/migrations', 'usage');
        $this->ready = true;
        $this->repo  = new UsageRepository($this->db);
        $this->clock = new FrozenClock(self::T0);

        $this->service = new UsageService(
            $this->repo,
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

    private function at(string $when): int
    {
        return (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private function dt(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
    }

    private function batch(string $id, string $when, string $tenant, array $counters, array $gauges = []): bool
    {
        return $this->repo->applyBatch(str_pad($id, 32, '0'), $this->at($when), $tenant, $counters, $gauges);
    }

    public function test_counters_accumulate_in_the_hour_bucket_across_batches(): void
    {
        $this->batch('a', '2026-03-05 13:10:00', 't1', ['orders' => 2]);
        $this->batch('b', '2026-03-05 13:50:00', 't1', ['orders' => 3]);
        $this->batch('c', '2026-03-05 14:05:00', 't1', ['orders' => 10]);

        $this->assertSame(15, $this->service->sum('orders', $this->dt('2026-03-05 13:00:00'), $this->dt('2026-03-05 15:00:00')));
        $this->assertSame(5, $this->service->sum('orders', $this->dt('2026-03-05 13:00:00'), $this->dt('2026-03-05 14:00:00')));
    }

    public function test_the_same_batch_delivered_twice_is_counted_once(): void
    {
        $this->assertTrue($this->batch('dup', '2026-03-05 13:10:00', 't1', ['orders' => 5]));
        $this->assertFalse($this->batch('dup', '2026-03-05 13:10:00', 't1', ['orders' => 5]));

        $this->assertSame(5, $this->service->sum('orders', $this->dt('2026-03-05 13:00:00'), $this->dt('2026-03-05 14:00:00')));
    }

    public function test_a_failure_midway_rolls_the_whole_batch_back_and_a_retry_then_applies_it(): void
    {
        $flaky = new FlakyDatabase($this->db, failOnWrite: 4);
        $repo  = new UsageRepository($flaky);

        $thrown = false;
        try {
            $repo->applyBatch(str_pad('x', 32, '0'), $this->at('2026-03-05 13:10:00'), 't1', ['a' => 1, 'b' => 2], []);
        } catch (RepositoryException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame(['buckets' => 0, 'gauges' => 0, 'batches' => 0], $this->repo->counts(), 'a half-applied batch was left behind');

        $flaky->heal();
        $this->assertTrue($repo->applyBatch(str_pad('x', 32, '0'), $this->at('2026-03-05 13:10:00'), 't1', ['a' => 1, 'b' => 2], []));
        $this->assertSame(1, $this->service->sum('a', $this->dt('2026-03-05 13:00:00'), $this->dt('2026-03-05 14:00:00')));
    }

    public function test_tenants_are_separate_and_null_means_all_of_them(): void
    {
        $this->batch('a', '2026-03-05 13:10:00', 't1', ['orders' => 2]);
        $this->batch('b', '2026-03-05 13:11:00', 't2', ['orders' => 5]);
        $this->batch('c', '2026-03-05 13:12:00', '', ['orders' => 1]);

        $from = $this->dt('2026-03-05 13:00:00');
        $to   = $this->dt('2026-03-05 14:00:00');

        $this->assertSame(8, $this->service->sum('orders', $from, $to));
        $this->assertSame(2, $this->service->sum('orders', $from, $to, 't1'));
        $this->assertSame(1, $this->service->sum('orders', $from, $to, ''));
        $this->assertSame(0, $this->service->sum('orders', $from, $to, 'nobody'));
    }

    public function test_the_latest_gauge_wins_and_an_older_batch_cannot_overwrite_it(): void
    {
        $this->batch('a', '2026-03-05 13:20:00', 't1', [], ['depth' => 10]);
        $this->batch('b', '2026-03-05 13:40:00', 't1', [], ['depth' => 4.5]);
        // Applied LAST but measured FIRST.
        $this->batch('c', '2026-03-05 13:30:00', 't1', [], ['depth' => 99]);

        $this->assertSame(4.5, $this->service->latest('depth', 't1'));

        $series = $this->service->series('depth', $this->dt('2026-03-05 13:00:00'), $this->dt('2026-03-05 14:00:00'));
        $this->assertSame(4.5, $series[0]->gauge, 'the bucket kept the stale value');
    }

    public function test_latest_across_tenants_adds_each_tenants_current_level(): void
    {
        $this->batch('a', '2026-03-05 13:20:00', 't1', [], ['depth' => 10]);
        $this->batch('b', '2026-03-05 13:21:00', 't2', [], ['depth' => 5]);
        $this->batch('c', '2026-03-05 13:30:00', 't1', [], ['depth' => 20]);

        $this->assertSame(25.0, $this->service->latest('depth'));
        $this->assertNull($this->service->latest('never.reported'));
    }

    public function test_series_returns_only_existing_buckets_oldest_first(): void
    {
        $this->batch('b', '2026-03-05 15:05:00', 't1', ['hits' => 1]);
        $this->batch('a', '2026-03-05 13:05:00', 't1', ['hits' => 2]);
        $this->batch('c', '2026-03-05 13:35:00', 't2', ['hits' => 3]);

        $series = $this->service->series('hits', $this->dt('2026-03-05 13:00:00'), $this->dt('2026-03-05 16:00:00'));

        $this->assertSame([$this->at('2026-03-05 13:00:00'), $this->at('2026-03-05 15:00:00')], array_map(static fn ($p) => $p->bucket, $series));
        $this->assertSame([5, 1], array_map(static fn ($p) => $p->counter, $series));
    }

    public function test_growth_compares_this_window_with_the_one_before(): void
    {
        // Clock 13:45. Window 2h => current = [12:00,14:00)... ends at END of current hour (14:00).
        $this->batch('p1', '2026-03-05 10:30:00', 't1', ['hits' => 10]); // previous window [10:00,12:00)
        $this->batch('p2', '2026-03-05 11:30:00', 't1', ['hits' => 10]);
        $this->batch('c1', '2026-03-05 12:30:00', 't1', ['hits' => 30]); // current window [12:00,14:00)
        $this->batch('c2', '2026-03-05 13:30:00', 't1', ['hits' => 10]);

        $growth = $this->service->growth('hits', 7200);

        $this->assertSame(40, $growth->current);
        $this->assertSame(20, $growth->previous);
        $this->assertSame(1.0, $growth->ratio());
    }

    public function test_growth_refuses_a_window_pair_that_outlives_hourly_retention(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->growth('hits', 3600 * 24 * 8); // two of them = 16 days > 14
    }

    public function test_growth_rejects_a_window_that_is_not_a_whole_number_of_hours(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->growth('hits', 5400);
    }

    public function test_rollup_builds_daily_rows_idempotently_and_keeps_the_latest_gauge(): void
    {
        // The LATER hour is applied FIRST, so the last row read is not the latest gauge.
        $this->batch('b', '2026-03-04 20:10:00', 't1', ['hits' => 6], ['depth' => 9]);
        $this->batch('a', '2026-03-04 02:10:00', 't1', ['hits' => 4], ['depth' => 1]);
        $this->batch('c', '2026-03-04 20:50:00', 't2', ['hits' => 1]);

        $first  = $this->service->rollup();
        $second = $this->service->rollup();

        $this->assertGreaterThanOrEqual(3, $first->daysRebuilt);
        $this->assertSame($first->daysRebuilt, $second->daysRebuilt);

        $day = $this->service->series('hits', $this->dt('2026-03-04 00:00:00'), $this->dt('2026-03-05 00:00:00'), Resolution::Day);

        $this->assertCount(1, $day);
        $this->assertSame(11, $day[0]->counter, 'rolling up twice must not double the total');

        $depth = $this->service->series('depth', $this->dt('2026-03-04 00:00:00'), $this->dt('2026-03-05 00:00:00'), Resolution::Day);
        $this->assertSame(9.0, $depth[0]->gauge);
    }

    public function test_the_daily_gauge_does_not_depend_on_the_order_the_database_returns_rows_in(): void
    {
        $this->batch('a', '2026-03-04 02:10:00', 't1', [], ['depth' => 1]);
        $this->batch('b', '2026-03-04 20:10:00', 't1', [], ['depth' => 9]);

        $reversed = new UsageRepository(new \Tests\Plugins\Usage\Support\ReversingDatabase($this->db));
        $reversed->rebuildDays([$this->at('2026-03-04 00:00:00')]);

        $day = $this->service->series('depth', $this->dt('2026-03-04 00:00:00'), $this->dt('2026-03-05 00:00:00'), Resolution::Day);

        $this->assertSame(9.0, $day[0]->gauge, 'the rollup took whichever row happened to come last');
    }

    public function test_hours_past_retention_are_pruned_but_their_day_total_survives(): void
    {
        // Clock is 2026-03-05. Retention 14d => hours before 02-19 13:45 go.
        $this->batch('old', '2026-02-10 08:00:00', 't1', ['hits' => 7]);
        $this->batch('new', '2026-03-05 08:00:00', 't1', ['hits' => 1]);

        // The day was folded while its hours were still inside retention.
        $this->repo->rebuildDays([$this->at('2026-02-10 00:00:00')]);

        $report = $this->service->rollup();

        $this->assertSame(1, $report->hourlyPruned);

        $this->assertSame(
            [],
            $this->service->series('hits', $this->dt('2026-02-10 00:00:00'), $this->dt('2026-02-11 00:00:00'), Resolution::Hour),
        );
        $this->assertSame(
            7,
            $this->service->sum('hits', $this->dt('2026-02-10 00:00:00'), $this->dt('2026-02-11 00:00:00')),
            'sum() must fall back to the daily row once the hours are gone',
        );
    }

    public function test_a_day_whose_hours_were_partly_pruned_keeps_its_complete_daily_total(): void
    {
        // Clock 2026-03-05 13:45; hourly retention 14d => hours before 02-19 13:45 are gone.
        // Day 02-19 was folded while complete (total 100). Only its 20:00 hour (5) survives.
        $this->repo->applyBatch(str_pad('late', 32, '0'), $this->at('2026-02-19 20:10:00'), 't1', ['hits' => 5], []);
        $this->db->execute(
            'INSERT INTO usage_buckets (tenant_id, metric, resolution, bucket, counter_sum, samples) VALUES (:t, :m, :r, :b, 100, 1)',
            ['t' => 't1', 'm' => 'hits', 'r' => Resolution::Day->value, 'b' => $this->at('2026-02-19 00:00:00')],
        );

        $this->service->rollup();

        $day = $this->service->series('hits', $this->dt('2026-02-19 00:00:00'), $this->dt('2026-02-20 00:00:00'), Resolution::Day);

        $this->assertSame(100, $day[0]->counter, 'a partial day was allowed to replace a complete total');
    }

    public function test_a_day_with_no_hourly_rows_left_is_not_zeroed_by_a_rebuild(): void
    {
        $this->repo->rebuildDays([$this->at('2026-02-10 00:00:00')]);
        $this->assertSame(0, $this->repo->counts()['buckets']);
    }

    public function test_daily_rows_are_kept_for_the_daily_retention_and_not_one_day_less_or_more(): void
    {
        $insert = fn (string $day) => $this->db->execute(
            'INSERT INTO usage_buckets (tenant_id, metric, resolution, bucket, counter_sum, samples) VALUES (:t, :m, :r, :b, 1, 1)',
            ['t' => 't1', 'm' => 'hits', 'r' => Resolution::Day->value, 'b' => $this->at($day)],
        );
        $insert('2025-01-01 00:00:00'); // ~429 days before the clock: past 400d retention
        $insert('2025-06-01 00:00:00'); // ~277 days: inside it

        $report = $this->service->rollup();

        $this->assertSame(1, $report->dailyPruned);
        $this->assertSame(
            1,
            (int) $this->db->queryOne('SELECT COUNT(*) AS n FROM usage_buckets WHERE resolution = :r', ['r' => Resolution::Day->value])['n'],
        );
    }

    public function test_old_batch_ids_are_forgotten_so_the_table_does_not_grow_forever(): void
    {
        $this->batch('old', '2026-03-01 00:00:00', 't1', ['a' => 1]);
        $this->batch('new', '2026-03-05 13:00:00', 't1', ['a' => 1]);

        $report = $this->service->rollup(); // batch retention 48h

        $this->assertSame(1, $report->batchesPruned);
        $this->assertSame(1, $this->repo->counts()['batches']);
    }

    public function test_the_whole_path_from_queue_message_to_row(): void
    {
        $queue = new FakeQueue();
        $acc   = new UsageAccumulator();
        $acc->forTenant('t9');
        $acc->count('orders', 3);
        $acc->gauge('depth', 7);

        (new UsageFlusher($queue, $this->clock))->flush($acc);

        $job = new FlushUsageJob($this->repo);
        $msg = $queue->pop('usage');
        $this->assertInstanceOf(JobPayload::class, $msg);

        $first  = $job->handle($msg);
        $second = $job->handle($msg); // delivered twice

        $this->assertSame(3, $this->service->sum('orders', $this->dt('2026-03-05 13:00:00'), $this->dt('2026-03-05 14:00:00'), 't9'));
        $this->assertSame(7.0, $this->service->latest('depth', 't9'));
        $this->assertTrue($first->isSuccess());
        $this->assertTrue($second->isSkipped());
        $this->assertSame('Batch already applied.', $second->skipReason());
    }

    public function test_malformed_and_hostile_payloads_are_skipped_not_retried(): void
    {
        $job   = new FlushUsageJob($this->repo);
        $queue = new FakeQueue();

        foreach ([
            [],
            ['batch' => 'short', 'at' => 1, 'tenant' => '', 'counters' => ['a' => 1]],
            ['batch' => str_repeat('a', 32), 'at' => 'x', 'tenant' => '', 'counters' => ['a' => 1]],
            ['batch' => str_repeat('a', 32), 'at' => 5, 'tenant' => '', 'counters' => ["x'; DROP" => 1, 'neg' => -5]],
        ] as $i => $data) {
            $queue->push('usage.flush', $data);
            $result = $job->handle($queue->pop());
            $this->assertTrue($result->isSkipped(), "payload #{$i} was not skipped");
        }

        $this->assertSame(['buckets' => 0, 'gauges' => 0, 'batches' => 0], $this->repo->counts());
    }

    public function test_describe_carries_no_credential(): void
    {
        $line = $this->service->describe();

        $this->assertStringContainsString('usage.metering', $line);
        $this->assertDoesNotMatchRegularExpression('/password|secret|dsn|mysql:|@/i', $line);
    }
}
