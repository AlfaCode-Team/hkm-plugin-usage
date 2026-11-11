<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Delegates to a real database and throws a PDOException on the Nth write, to
 * prove a half-applied batch is rolled back rather than left behind.
 */
final class FlakyDatabase implements DatabasePort
{
    private int $writes = 0;

    public function __construct(private readonly DatabasePort $inner, private int $failOnWrite = PHP_INT_MAX) {}

    public function heal(): void
    {
        $this->failOnWrite = PHP_INT_MAX;
    }

    private function write(): void
    {
        if (++$this->writes === $this->failOnWrite) {
            throw new \PDOException('simulated write failure');
        }
    }

    public function query(string $sql, array $params = []): array { return $this->inner->query($sql, $params); }
    public function queryOne(string $sql, array $params = []): ?array { return $this->inner->queryOne($sql, $params); }
    public function execute(string $sql, array $params = []): int { $this->write(); return $this->inner->execute($sql, $params); }
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { $this->write(); return $this->inner->upsert($table, $values, $conflictColumns, $updateColumns); }
    public function lastInsertId(?string $sequence = null): string { return $this->inner->lastInsertId($sequence); }
    public function beginTransaction(): void { $this->inner->beginTransaction(); }
    public function commit(): void { $this->inner->commit(); }
    public function rollback(): void { $this->inner->rollback(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
}
