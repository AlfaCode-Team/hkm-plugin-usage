<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Returns every multi-row result in REVERSE order.
 *
 * SQL promises no order without ORDER BY, and SQLite and InnoDB happen to hand
 * rows back in primary-key order. Code that silently depends on that works on
 * every developer machine and breaks on the day a planner picks another index;
 * this makes the day come on demand.
 */
final class ReversingDatabase implements DatabasePort
{
    public function __construct(private readonly DatabasePort $inner) {}

    public function query(string $sql, array $params = []): array { return array_reverse($this->inner->query($sql, $params)); }
    public function queryOne(string $sql, array $params = []): ?array { return $this->inner->queryOne($sql, $params); }
    public function execute(string $sql, array $params = []): int { return $this->inner->execute($sql, $params); }
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { return $this->inner->upsert($table, $values, $conflictColumns, $updateColumns); }
    public function lastInsertId(?string $sequence = null): string { return $this->inner->lastInsertId($sequence); }
    public function beginTransaction(): void { $this->inner->beginTransaction(); }
    public function commit(): void { $this->inner->commit(); }
    public function rollback(): void { $this->inner->rollback(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
}
