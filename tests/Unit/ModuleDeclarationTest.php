<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use PHPUnit\Framework\TestCase;
use Plugins\Usage\Infrastructure\Config\UsageConfig;
use Plugins\Usage\Provider;

final class ModuleDeclarationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/module.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_scheduled_task_is_guarded_against_overlap_and_names_a_real_command(): void
    {
        foreach ($this->manifest()['schedule'] as $entry) {
            $this->assertTrue($entry['withoutOverlapping'] ?? false, "[{$entry['name']}] must not overlap");
            $this->assertGreaterThanOrEqual(60, $entry['expiresAfter']);
        }
    }

    public function test_the_flush_job_is_declared_and_the_flusher_pushes_exactly_that_name(): void
    {
        $jobs = array_column($this->manifest()['jobs'], null, 'name');

        $this->assertArrayHasKey(\Plugins\Usage\Application\Services\UsageFlusher::JOB, $jobs);
        $this->assertTrue(is_subclass_of($jobs['usage.flush']['handler'], JobContract::class));
    }

    public function test_the_manifest_has_a_kernel_floor_declares_no_routes_and_mirrors_the_provider(): void
    {
        $m = $this->manifest();

        $this->assertNotEmpty($m['kernel']);
        $this->assertSame([], $m['routes']);
        $this->assertSame($m['solves'], (new Provider())->solves());
        $this->assertSame($m['requires'], (new Provider())->requires());
        $this->assertSame($m['exposes'], (new Provider())->exposes());
    }

    public function test_every_declared_default_matches_the_defaults_the_code_uses(): void
    {
        $declared = [];
        foreach ($this->manifest()['config'] as $c) {
            $declared[$c['key']] = $c['default'];
        }

        $defaults = new UsageConfig();

        $this->assertSame($declared['USAGE_QUEUE'], $defaults->queue);
        $this->assertSame($declared['USAGE_TRACK_REQUESTS'], $defaults->trackRequests);
        $this->assertSame($declared['USAGE_MAX_METRICS'], $defaults->maxMetrics);
        $this->assertSame($declared['USAGE_HOURLY_RETENTION_DAYS'], $defaults->hourlyRetentionDays);
        $this->assertSame($declared['USAGE_DAILY_RETENTION_DAYS'], $defaults->dailyRetentionDays);
        $this->assertSame($declared['USAGE_BATCH_RETENTION_HOURS'], $defaults->batchRetentionHours);
    }
}
