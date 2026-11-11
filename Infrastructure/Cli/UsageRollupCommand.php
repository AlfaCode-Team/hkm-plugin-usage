<?php

declare(strict_types=1);

namespace Plugins\Usage\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Usage\API\Contracts\UsageContract;

/**
 * usage:rollup — fold hourly buckets into daily ones, then prune past retention.
 *
 * Safe to repeat: daily rows are rebuilt from the hourly ones, never added to.
 */
final class UsageRollupCommand extends AbstractCommand
{
    public function __construct(private readonly UsageContract $usage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'usage:rollup';
        $this->description = 'Roll hourly usage up into daily buckets and delete what is past retention';

        $this->addOption('quiet-when-idle', '', 'Print nothing when there was nothing to do');
    }

    protected function handle(): int
    {
        $report = $this->usage->rollup();

        $idle = $report->daysRebuilt + $report->hourlyPruned + $report->dailyPruned + $report->batchesPruned === 0;

        if ($idle) {
            if (!$this->hasOption('quiet-when-idle')) {
                $this->muted('Nothing to roll up or prune.');
            }

            return self::SUCCESS;
        }

        $this->success(sprintf(
            'Rebuilt %d daily row(s); pruned %d hourly, %d daily, %d batch id(s).',
            $report->daysRebuilt,
            $report->hourlyPruned,
            $report->dailyPruned,
            $report->batchesPruned,
        ));

        return self::SUCCESS;
    }
}
