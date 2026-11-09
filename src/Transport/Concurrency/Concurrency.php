<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Transport\Concurrency;

use CurlHandle;

/**
 * Where waiting happens. Code above — the transport, the retry loop — calls perform() and
 * sleep() and never asks which environment it runs in:
 *
 * - in a fiber of a running FiberRunner: perform() adds the handle to the shared curl_multi
 *   and suspends the fiber; sleep() suspends it on a timer;
 * - in a Swoole coroutine: curl_exec() (non-blocking under the curl-native hook) and
 *   Swoole\Coroutine::sleep();
 * - anywhere else (FPM, CLI): curl_exec() and usleep().
 *
 * @internal
 */
final class Concurrency
{
    /** Runs one transfer to its end; returns the cURL error number (0 — success). */
    public static function perform(CurlHandle $handle): int
    {
        $runner = FiberRunner::current();
        if ($runner !== null && $runner->ownsCurrentFiber()) {
            return $runner->await($handle);
        }

        curl_exec($handle);

        return curl_errno($handle);
    }

    /** Pauses for `$ms` milliseconds without holding up other concurrent requests. */
    public static function sleep(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        $runner = FiberRunner::current();
        if ($runner !== null && $runner->ownsCurrentFiber()) {
            $runner->sleep($ms);
            return;
        }
        if (self::inCoroutine()) {
            \Swoole\Coroutine::sleep($ms / 1000);
            return;
        }
        usleep($ms * 1000);
    }

    /**
     * Runs `$tasks` with at most `$concurrency` at a time.
     *
     * @param iterable<array-key, \Closure(): mixed> $tasks
     * @return array{0: array<array-key, mixed>, 1: array<array-key, \Throwable>, 2: list<array-key>}
     *         results and failures by the tasks' keys, and the keys in input order
     */
    public static function run(iterable $tasks, int $concurrency): array
    {
        $concurrency = max(1, $concurrency);

        if (self::inCoroutine()) {
            return (new CoroutineRunner())->run($tasks, $concurrency);
        }

        // Inside a fiber of a runner already (sendAll() from a middleware, say): run in order —
        // each transfer still waits through the outer runner, so nothing blocks.
        $outer = FiberRunner::current();
        if ($outer !== null && $outer->ownsCurrentFiber()) {
            return self::sequential($tasks);
        }

        return (new FiberRunner())->run($tasks, $concurrency);
    }

    public static function inCoroutine(): bool
    {
        return extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * @param iterable<array-key, \Closure(): mixed> $tasks
     * @return array{0: array<array-key, mixed>, 1: array<array-key, \Throwable>, 2: list<array-key>}
     */
    private static function sequential(iterable $tasks): array
    {
        $results = $failures = $order = [];
        foreach ($tasks as $key => $task) {
            $order[] = $key;
            try {
                $results[$key] = $task();
            } catch (\Throwable $e) {
                $failures[$key] = $e;
            }
        }

        return [$results, $failures, $order];
    }
}
