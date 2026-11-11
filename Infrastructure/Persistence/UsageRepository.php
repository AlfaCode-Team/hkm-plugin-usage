<?php

declare(strict_types=1);

namespace Plugins\Usage\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Usage\Application\Ports\UsageStore;
use Plugins\Usage\Domain\Point;
use Plugins\Usage\Domain\Resolution;

/**
 * DatabasePort only.
 *
 * ── WHY A COUNTER IS "ENSURE THE ROW, THEN ADD" ─────────────────────────────
 * `DatabasePort::upsert()` REPLACES columns with the incoming values; it cannot
 * express `counter_sum = counter_sum + n`, and hand-writing ON DUPLICATE KEY /
 * ON CONFLICT is not allowed in a repository. So each bucket is created
 * insert-if-absent (`$updateColumns = []`) and then incremented with a plain
 * `UPDATE … SET x = x + :n`. The increment is atomic on every driver, and two
 * workers racing to create the same bucket both succeed: one inserts, the other
 * is a no-op, and both increments land.
 *
 * ── THE DatabasePort INSTANCE MUST BE SHARED ────────────────────────────────
 * The Database plugin's port is transient: each make() is a new connection. The
 * transaction in applyBatch() only holds if every statement runs on the SAME
 * instance, so this class takes ONE port in its constructor and the Provider
 * resolves it once per request.
 */
final class UsageRepository implements UsageStore
{
    private const LAYER = 'repository.usage';

    public function __construct(private readonly DatabasePort $db) {}

    public function applyBatch(string $batchId, int $at, string $tenant, array $counters, array $gauges): bool
    {
        $bucket = Resolution::Hour->floor($at);

        $this->db->beginTransaction();

        try {
            // Insert-if-absent returns the rows it inserted: 0 means this batch
            // id was applied already. Same transaction as the counters below, so
            // "recorded as applied" and "applied" cannot disagree.
            if ($this->db->upsert('usage_batches', ['batch_id' => $batchId, 'applied_at' => $at], ['batch_id'], []) === 0) {
                $this->db->rollback();

                return false;
            }

            foreach ($counters as $metric => $value) {
                $this->ensureBucket($tenant, (string) $metric, $bucket);
                $this->db->execute(
                    'UPDATE usage_buckets SET counter_sum = counter_sum + :v, samples = samples + 1 '
                    . 'WHERE tenant_id = :t AND metric = :m AND resolution = :r AND bucket = :b',
                    ['v' => $value, 't' => $tenant, 'm' => (string) $metric, 'r' => Resolution::Hour->value, 'b' => $bucket],
                );
            }

            foreach ($gauges as $metric => $value) {
                $this->ensureBucket($tenant, (string) $metric, $bucket);

                // `gauge_at <= :at`: a batch applied out of order must not put an
                // older level over a newer one.
                $this->db->execute(
                    'UPDATE usage_buckets SET gauge_value = :v, gauge_at = :at, samples = samples + 1 '
                    . 'WHERE tenant_id = :t AND metric = :m AND resolution = :r AND bucket = :b '
                    . 'AND (gauge_at IS NULL OR gauge_at <= :at2)',
                    ['v' => $value, 'at' => $at, 'at2' => $at, 't' => $tenant, 'm' => (string) $metric,
                     'r' => Resolution::Hour->value, 'b' => $bucket],
                );

                $this->db->upsert('usage_gauges', ['tenant_id' => $tenant, 'metric' => (string) $metric, 'value' => $value, 'at' => $at],
                    ['tenant_id', 'metric'], []);
                $this->db->execute(
                    'UPDATE usage_gauges SET value = :v, at = :at WHERE tenant_id = :t AND metric = :m AND at <= :at2',
                    ['v' => $value, 'at' => $at, 'at2' => $at, 't' => $tenant, 'm' => (string) $metric],
                );
            }

            $this->db->commit();

            return true;
        } catch (\PDOException $e) {
            $this->abort();

            throw new RepositoryException('Failed to apply usage batch', layer: self::LAYER, previous: $e);
        } catch (\Throwable $e) {
            $this->abort();

            throw $e;
        }
    }

    public function sum(string $metric, Resolution $resolution, int $from, int $to, ?string $tenant): int
    {
        [$where, $params] = $this->range($metric, $resolution, $from, $to, $tenant);

        try {
            $row = $this->db->queryOne("SELECT COALESCE(SUM(counter_sum), 0) AS s FROM usage_buckets WHERE {$where}", $params);
        } catch (\PDOException $e) {
            throw new RepositoryException("Failed to sum usage [{$metric}]", layer: self::LAYER, previous: $e);
        }

        return (int) ($row['s'] ?? 0);
    }

    public function series(string $metric, Resolution $resolution, int $from, int $to, ?string $tenant): array
    {
        [$where, $params] = $this->range($metric, $resolution, $from, $to, $tenant);

        try {
            $rows = $this->db->query(
                'SELECT bucket, SUM(counter_sum) AS c, SUM(gauge_value) AS g, COUNT(gauge_at) AS gn '
                . "FROM usage_buckets WHERE {$where} GROUP BY bucket ORDER BY bucket",
                $params,
            );
        } catch (\PDOException $e) {
            throw new RepositoryException("Failed to read usage series [{$metric}]", layer: self::LAYER, previous: $e);
        }

        return array_map(
            static fn (array $r): Point => new Point(
                (int) $r['bucket'],
                (int) $r['c'],
                (int) $r['gn'] > 0 ? (float) $r['g'] : null,
            ),
            $rows,
        );
    }

    public function latest(string $metric, ?string $tenant): ?float
    {
        try {
            $row = $tenant === null
                ? $this->db->queryOne('SELECT SUM(value) AS v, COUNT(*) AS n FROM usage_gauges WHERE metric = :m', ['m' => $metric])
                : $this->db->queryOne('SELECT value AS v, 1 AS n FROM usage_gauges WHERE metric = :m AND tenant_id = :t', ['m' => $metric, 't' => $tenant]);
        } catch (\PDOException $e) {
            throw new RepositoryException("Failed to read gauge [{$metric}]", layer: self::LAYER, previous: $e);
        }

        return $row === null || (int) $row['n'] === 0 || $row['v'] === null ? null : (float) $row['v'];
    }

    public function rebuildDays(array $dayStarts): int
    {
        $written = 0;

        try {
            foreach ($dayStarts as $day) {
                $end    = $day + Resolution::Day->value;
                $params = ['r' => Resolution::Hour->value, 'a' => $day, 'b' => $end];

                $sums = $this->db->query(
                    'SELECT tenant_id, metric, SUM(counter_sum) AS c, SUM(samples) AS s FROM usage_buckets '
                    . 'WHERE resolution = :r AND bucket >= :a AND bucket < :b GROUP BY tenant_id, metric',
                    $params,
                );

                if ($sums === []) {
                    // An optimisation, NOT a guard: with no hourly rows the loop
                    // below writes nothing anyway, so removing this changes no
                    // result (a revert check confirmed it) — it only skips the
                    // gauge query. What keeps a pruned day's total from being
                    // replaced by a partial one is the day ALIGNMENT in
                    // UsageService::rollup(), which never asks for such a day.
                    continue;
                }

                // Latest gauge per series, folded here: one day of hourly rows is
                // bounded (24 x series), and "the value with the greatest
                // gauge_at" is not portable across the three dialects in one query.
                // The comparison below is what makes the result independent of row
                // order: SQL promises none, and a revert check showed that without
                // it the answer is right ONLY because SQLite returns primary-key
                // order (tests/Support/ReversingDatabase.php makes it fail on demand).
                $latest = [];
                foreach ($this->db->query(
                    'SELECT tenant_id, metric, gauge_value, gauge_at FROM usage_buckets '
                    . 'WHERE resolution = :r AND bucket >= :a AND bucket < :b AND gauge_at IS NOT NULL',
                    $params,
                ) as $g) {
                    $key = $g['tenant_id'] . "\0" . $g['metric'];
                    if (!isset($latest[$key]) || (int) $g['gauge_at'] >= $latest[$key]['at']) {
                        $latest[$key] = ['value' => (float) $g['gauge_value'], 'at' => (int) $g['gauge_at']];
                    }
                }

                foreach ($sums as $row) {
                    $g = $latest[$row['tenant_id'] . "\0" . $row['metric']] ?? null;

                    // REPLACE, never add: derived from the hourly rows, so running
                    // it twice gives the same answer as running it once.
                    $this->db->upsert('usage_buckets', [
                        'tenant_id'   => $row['tenant_id'],
                        'metric'      => $row['metric'],
                        'resolution'  => Resolution::Day->value,
                        'bucket'      => $day,
                        'counter_sum' => (int) $row['c'],
                        'samples'     => (int) $row['s'],
                        'gauge_value' => $g['value'] ?? null,
                        'gauge_at'    => $g['at'] ?? null,
                    ], ['tenant_id', 'metric', 'resolution', 'bucket'], ['counter_sum', 'samples', 'gauge_value', 'gauge_at']);

                    $written++;
                }
            }
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to roll usage up into days', layer: self::LAYER, previous: $e);
        }

        return $written;
    }

    public function prune(int $hourlyBefore, int $dailyBefore, int $batchesBefore): array
    {
        try {
            return [
                'hourly'  => $this->db->execute('DELETE FROM usage_buckets WHERE resolution = :r AND bucket < :t', ['r' => Resolution::Hour->value, 't' => $hourlyBefore]),
                'daily'   => $this->db->execute('DELETE FROM usage_buckets WHERE resolution = :r AND bucket < :t', ['r' => Resolution::Day->value, 't' => $dailyBefore]),
                'batches' => $this->db->execute('DELETE FROM usage_batches WHERE applied_at < :t', ['t' => $batchesBefore]),
            ];
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to prune usage', layer: self::LAYER, previous: $e);
        }
    }

    public function counts(): array
    {
        try {
            return [
                'buckets' => (int) ($this->db->queryOne('SELECT COUNT(*) AS n FROM usage_buckets')['n'] ?? 0),
                'gauges'  => (int) ($this->db->queryOne('SELECT COUNT(*) AS n FROM usage_gauges')['n'] ?? 0),
                'batches' => (int) ($this->db->queryOne('SELECT COUNT(*) AS n FROM usage_batches')['n'] ?? 0),
            ];
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to count usage rows', layer: self::LAYER, previous: $e);
        }
    }

    private function ensureBucket(string $tenant, string $metric, int $bucket): void
    {
        $this->db->upsert('usage_buckets', [
            'tenant_id' => $tenant, 'metric' => $metric, 'resolution' => Resolution::Hour->value,
            'bucket' => $bucket, 'counter_sum' => 0, 'samples' => 0,
        ], ['tenant_id', 'metric', 'resolution', 'bucket'], []);
    }

    private function abort(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollback();
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function range(string $metric, Resolution $resolution, int $from, int $to, ?string $tenant): array
    {
        $where  = 'metric = :m AND resolution = :r AND bucket >= :a AND bucket < :b';
        $params = ['m' => $metric, 'r' => $resolution->value, 'a' => $from, 'b' => $to];

        if ($tenant !== null) {
            $where .= ' AND tenant_id = :t';
            $params['t'] = $tenant;
        }

        return [$where, $params];
    }
}
