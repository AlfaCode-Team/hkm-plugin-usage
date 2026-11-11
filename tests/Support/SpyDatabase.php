<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * A DatabasePort that does nothing and counts every call.
 *
 * Bound where a real one would be, so that "the request path never touches the
 * database" is an assertion about `$calls`, not a belief about the code.
 */
final class SpyDatabase implements DatabasePort
{
    public int $calls = 0;

    /** @var list<string> */
    public array $log = [];

    private function touch(string $what): void
    {
        $this->calls++;
        $this->log[] = $what;
    }

    public function query(string $sql, array $params = []): array { $this->touch('query'); return []; }
    public function queryOne(string $sql, array $params = []): ?array { $this->touch('queryOne'); return null; }
    public function execute(string $sql, array $params = []): int { $this->touch('execute'); return 0; }
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { $this->touch('upsert'); return 0; }
    public function lastInsertId(?string $sequence = null): string { $this->touch('lastInsertId'); return '0'; }
    public function beginTransaction(): void { $this->touch('begin'); }
    public function commit(): void { $this->touch('commit'); }
    public function rollback(): void { $this->touch('rollback'); }
    public function inTransaction(): bool { $this->touch('inTransaction'); return false; }
}
