<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Usage\Domain\Growth;
use Plugins\Usage\Domain\MetricName;
use Plugins\Usage\Domain\Resolution;
use Plugins\Usage\Domain\UsageAccumulator;

final class UsageAccumulatorTest extends TestCase
{
    public function test_counters_add_up_and_gauges_keep_the_last_value(): void
    {
        $a = new UsageAccumulator();
        $a->count('orders');
        $a->count('orders', 4);
        $a->gauge('queue.depth', 10);
        $a->gauge('queue.depth', 3.5);

        $out = $a->drain();

        $this->assertSame(['orders' => 5], $out['counters']);
        $this->assertSame(['queue.depth' => 3.5], $out['gauges']);
    }

    public function test_drain_empties_so_a_second_flush_cannot_count_twice(): void
    {
        $a = new UsageAccumulator();
        $a->count('orders');

        $this->assertFalse($a->isEmpty());
        $a->drain();

        $this->assertTrue($a->isEmpty());
        $this->assertSame([], $a->drain()['counters']);
    }

    public function test_a_counter_cannot_decrease(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new UsageAccumulator())->count('orders', -1);
    }

    public function test_a_gauge_must_be_finite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new UsageAccumulator())->gauge('load', NAN);
    }

    /** @return array<string, array{string}> */
    public static function badNames(): array
    {
        return [
            'empty'       => [''],
            'upper case'  => ['Orders'],
            'leading dot' => ['.orders'],
            'space'       => ['my orders'],
            'sql'         => ["a'; DROP TABLE x;--"],
            'too long'    => [str_repeat('a', 129)],
        ];
    }

    #[DataProvider('badNames')]
    public function test_bad_metric_names_are_rejected(string $name): void
    {
        $this->assertFalse(MetricName::isValid($name));
        $this->expectException(\InvalidArgumentException::class);

        (new UsageAccumulator())->count($name);
    }

    public function test_distinct_names_past_the_cap_are_dropped_and_counted_not_stored(): void
    {
        $a = new UsageAccumulator(maxMetrics: 3);

        foreach (range(1, 10) as $i) {
            $a->count("m{$i}");
        }
        $a->count('m1', 5); // an existing name still accumulates

        $out = $a->drain();

        $this->assertCount(3, $out['counters']);
        $this->assertSame(6, $out['counters']['m1']);
        $this->assertSame(7, $a->dropped());
    }

    public function test_tenant_is_truncated_to_the_column_width(): void
    {
        $a = new UsageAccumulator();
        $a->forTenant(str_repeat('t', 200));

        $this->assertSame(64, \strlen($a->tenant()));
    }

    public function test_growth_ratio_is_null_from_zero_not_infinite(): void
    {
        $this->assertNull((new Growth(10, 0))->ratio());
        $this->assertSame(1.0, (new Growth(20, 10))->ratio());
        $this->assertSame(-0.5, (new Growth(5, 10))->ratio());
        $this->assertSame(10, (new Growth(20, 10))->delta());
    }

    public function test_buckets_are_utc_and_floor_down(): void
    {
        $t = gmmktime(13, 45, 10, 3, 5, 2026);

        $this->assertSame(gmmktime(13, 0, 0, 3, 5, 2026), Resolution::Hour->floor($t));
        $this->assertSame(gmmktime(0, 0, 0, 3, 5, 2026), Resolution::Day->floor($t));
    }
}
