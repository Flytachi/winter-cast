<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Transport\Concurrency;

/**
 * Concurrency inside a Swoole coroutine: each task is its own coroutine. With the
 * curl-native hook (SWOOLE_HOOK_ALL) curl_exec yields while it waits, so the tasks really
 * overlap; a channel caps how many run at once.
 *
 * @internal
 */
final class CoroutineRunner
{
    /**
     * @param iterable<array-key, \Closure(): mixed> $tasks
     * @return array{0: array<array-key, mixed>, 1: array<array-key, \Throwable>, 2: list<array-key>}
     */
    public function run(iterable $tasks, int $concurrency): array
    {
        $results = $failures = $order = [];
        $slots = new \Swoole\Coroutine\Channel($concurrency);
        $group = new \Swoole\Coroutine\WaitGroup();

        foreach ($tasks as $key => $task) {
            $order[] = $key;
            $slots->push(true);          // blocks this coroutine while `$concurrency` are running
            $group->add();
            $run = static function () use ($key, $task, $slots, $group, &$results, &$failures): void {
                try {
                    $results[$key] = $task();
                } catch (\Throwable $e) {
                    $failures[$key] = $e;
                } finally {
                    $slots->pop();
                    $group->done();
                }
            };
            \Swoole\Coroutine::create($run);
        }
        $group->wait();

        return [$results, $failures, $order];
    }
}
