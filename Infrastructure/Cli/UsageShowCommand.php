<?php

declare(strict_types=1);

namespace Plugins\Usage\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Usage\API\Contracts\UsageContract;
use Plugins\Usage\Domain\Resolution;

/**
 * usage:show — the status line, and optionally one metric's recent buckets.
 */
final class UsageShowCommand extends AbstractCommand
{
    public function __construct(
        private readonly UsageContract $usage,
        private readonly \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'usage:show';
        $this->description = 'Show metering status, or one metric over the last N hours';

        $this->addArgument('metric', 'A metric name, e.g. http.requests');
        $this->addOption('hours', 'H', 'How many hours back (default 24)', acceptsValue: true);
        $this->addOption('tenant', 't', 'Only this tenant', acceptsValue: true);
    }

    protected function handle(): int
    {
        $this->section('Usage');
        $this->muted($this->usage->describe());

        $metric = $this->argument('metric');
        if ($metric === null || $metric === '') {
            return self::SUCCESS;
        }

        try {
            $hours  = max(1, (int) ($this->option('hours') ?? 24));
            $tenant = $this->option('tenant');
            $to     = $this->clock->now()->modify('+1 hour');
            $from   = $to->modify("-{$hours} hours");

            $series = $this->usage->series((string) $metric, $from, $to, Resolution::Hour, $tenant === null ? null : (string) $tenant);
            $latest = $this->usage->latest((string) $metric, $tenant === null ? null : (string) $tenant);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Metric {$metric}, last {$hours}h" . ($latest === null ? '' : " — latest gauge {$latest}"));

        if ($series === []) {
            $this->muted('No buckets in that range.');

            return self::SUCCESS;
        }

        foreach ($series as $point) {
            $this->info(sprintf(
                '  %s  counter %-12d gauge %s',
                gmdate('Y-m-d H:i', $point->bucket),
                $point->counter,
                $point->gauge === null ? '-' : (string) $point->gauge,
            ));
        }

        return self::SUCCESS;
    }
}
