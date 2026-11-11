<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Unit;

use AlfacodeTeam\Ground\Fakes\FakeLogger;
use AlfacodeTeam\Ground\Fakes\FakeQueue;
use AlfacodeTeam\Ground\Fakes\FrozenClock;
use PHPUnit\Framework\TestCase;
use Plugins\Usage\Application\Services\UsageFlusher;
use Plugins\Usage\Domain\UsageAccumulator;
use Tests\Plugins\Usage\Support\DownQueue;

final class UsageFlusherTest extends TestCase
{
    public function test_an_empty_accumulator_pushes_nothing(): void
    {
        $queue = new FakeQueue();

        $this->assertFalse((new UsageFlusher($queue, new FrozenClock()))->flush(new UsageAccumulator()));
        $this->assertSame([], $queue->pushed);
    }

    public function test_flushing_twice_does_not_send_the_same_increments_twice(): void
    {
        $queue = new FakeQueue();
        $flusher = new UsageFlusher($queue, new FrozenClock(), 'q1');
        $acc = new UsageAccumulator();
        $acc->count('a', 3);

        $this->assertTrue($flusher->flush($acc));
        $this->assertFalse($flusher->flush($acc));

        $this->assertCount(1, $queue->pushed);
        $this->assertSame('q1', $queue->pushed[0]['queue']);
    }

    public function test_every_batch_gets_its_own_idempotency_key(): void
    {
        $queue = new FakeQueue();
        $flusher = new UsageFlusher($queue, new FrozenClock());

        foreach ([1, 2] as $_) {
            $acc = new UsageAccumulator();
            $acc->count('a');
            $flusher->flush($acc);
        }

        $this->assertNotSame($queue->pushed[0]['payload']['batch'], $queue->pushed[1]['payload']['batch']);
    }

    public function test_a_failing_queue_is_logged_and_swallowed_and_the_batch_is_not_retried_in_process(): void
    {
        $logger = new FakeLogger();
        $queue  = new DownQueue();
        $flusher = new UsageFlusher($queue, new FrozenClock(), 'usage', $logger);
        $acc = new UsageAccumulator();
        $acc->count('a');

        $this->assertFalse($flusher->flush($acc));
        $this->assertFalse($flusher->flush($acc));
        $this->assertSame(1, $queue->attempts);
        $this->assertTrue($logger->logged('warning', 'dropped'));
    }

    public function test_without_a_queue_the_batch_is_dropped(): void
    {
        $acc = new UsageAccumulator();
        $acc->count('a');

        $this->assertFalse((new UsageFlusher(null, new FrozenClock()))->flush($acc));
        $this->assertTrue($acc->isEmpty());
    }
}
