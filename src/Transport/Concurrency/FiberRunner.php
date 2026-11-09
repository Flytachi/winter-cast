<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Transport\Concurrency;

use CurlHandle;
use CurlMultiHandle;
use Fiber;

/**
 * Concurrency without Swoole: each task runs in its own Fiber; when a task's transfer would
 * block, its cURL handle joins one shared curl_multi and the fiber suspends. One loop drives
 * every transfer and resumes each fiber when its response is in.
 *
 * Fibers keep the code above ordinary and synchronous — a middleware calls `$next($request)`
 * and gets a response — while the waiting on the network is shared.
 *
 * @internal
 */
final class FiberRunner
{
    private static ?self $current = null;

    private CurlMultiHandle $multi;

    /** @var array<int, Fiber> spl_object_id(handle) => the fiber waiting for it */
    private array $waiting = [];

    /** @var array<int, CurlHandle> */
    private array $handles = [];

    /** @var list<array{0: int, 1: Fiber}> [wake-up at (hrtime ns), fiber] */
    private array $timers = [];

    /** @var \WeakMap<Fiber, true> */
    private \WeakMap $owned;

    public function __construct()
    {
        $this->multi = curl_multi_init();
        $this->owned = new \WeakMap();
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public function ownsCurrentFiber(): bool
    {
        $fiber = Fiber::getCurrent();

        return $fiber !== null && isset($this->owned[$fiber]);
    }

    /** Called from a task's fiber: hands the transfer to the shared loop and waits for it. */
    public function await(CurlHandle $handle): int
    {
        $id = spl_object_id($handle);
        $this->waiting[$id] = Fiber::getCurrent();
        $this->handles[$id] = $handle;
        curl_multi_add_handle($this->multi, $handle);

        return (int) Fiber::suspend();
    }

    /** Called from a task's fiber: lets the others run for `$ms`. */
    public function sleep(int $ms): void
    {
        $this->timers[] = [hrtime(true) + $ms * 1_000_000, Fiber::getCurrent()];
        Fiber::suspend();
    }

    /**
     * @param iterable<array-key, \Closure(): mixed> $tasks
     * @return array{0: array<array-key, mixed>, 1: array<array-key, \Throwable>, 2: list<array-key>}
     */
    public function run(iterable $tasks, int $concurrency): array
    {
        $previous = self::$current;
        self::$current = $this;

        $results = $failures = $order = [];
        /** @var array<int, array{0: array-key, 1: Fiber}> $active fiber id => [key, fiber] */
        $active = [];
        $iterator = (static fn() => yield from $tasks)();

        $finish = function (int $id) use (&$active, &$results, &$failures): void {
            [$key, $fiber] = $active[$id];
            unset($active[$id]);
            [$ok, $value] = $fiber->getReturn();
            if ($ok) {
                $results[$key] = $value;
            } else {
                $failures[$key] = $value;
            }
        };

        $startNext = function () use ($iterator, &$active, &$order, $finish): void {
            if (!$iterator->valid()) {
                return;
            }
            $key = $iterator->key();
            $task = $iterator->current();
            $iterator->next();
            $order[] = $key;

            $fiber = new Fiber(static function () use ($task): array {
                try {
                    return [true, $task()];
                } catch (\Throwable $e) {
                    return [false, $e];
                }
            });
            $this->owned[$fiber] = true;
            $active[spl_object_id($fiber)] = [$key, $fiber];
            $fiber->start();
            if ($fiber->isTerminated()) {
                $finish(spl_object_id($fiber));
            }
        };

        try {
            while (count($active) < $concurrency && $iterator->valid()) {
                $startNext();
            }

            while ($active !== []) {
                $this->tick();
                foreach ($active as $id => [, $fiber]) {
                    if ($fiber->isTerminated()) {
                        $finish($id);
                    }
                }
                while (count($active) < $concurrency && $iterator->valid()) {
                    $startNext();
                }
            }
        } finally {
            foreach ($this->handles as $handle) {
                curl_multi_remove_handle($this->multi, $handle);
            }
            $this->handles = $this->waiting = $this->timers = [];
            self::$current = $previous;
        }

        return [$results, $failures, $order];
    }

    /** One turn of the loop: drive transfers, resume whoever is ready. */
    private function tick(): void
    {
        if ($this->waiting !== []) {
            do {
                $status = curl_multi_exec($this->multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while (($info = curl_multi_info_read($this->multi)) !== false) {
                $handle = $info['handle'];
                $id = spl_object_id($handle);
                curl_multi_remove_handle($this->multi, $handle);
                $fiber = $this->waiting[$id] ?? null;
                unset($this->waiting[$id], $this->handles[$id]);
                $fiber?->resume((int) $info['result']);
            }
        }

        $now = hrtime(true);
        $due = [];
        foreach ($this->timers as $i => [$at, $fiber]) {
            if ($at <= $now) {
                $due[] = $fiber;
                unset($this->timers[$i]);
            }
        }
        foreach ($due as $fiber) {
            $fiber->resume();
        }

        if ($due !== []) {
            return;
        }
        $wait = $this->secondsToNextTimer() ?? 1.0;
        if ($this->waiting !== []) {
            if (curl_multi_select($this->multi, min($wait, 0.25)) === -1) {
                usleep(1000);
            }
        } elseif ($this->timers !== []) {
            usleep((int) (min($wait, 0.25) * 1_000_000));
        }
    }

    private function secondsToNextTimer(): ?float
    {
        if ($this->timers === []) {
            return null;
        }
        $next = min(array_column($this->timers, 0));

        return max(0.0, ($next - hrtime(true)) / 1e9);
    }
}
