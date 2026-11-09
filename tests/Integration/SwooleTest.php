<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Integration;

use Flytachi\Winter\Cast\Client\CastClient;
use Flytachi\Winter\Cast\Client\RetryPolicy;
use Flytachi\Winter\Cast\Message\CastResponse;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * The same client inside Swoole coroutines (curl-native hook): concurrent sends from several
 * coroutines, sendAll() on coroutines, retry pauses that yield.
 */
#[RequiresPhpExtension('swoole')]
final class SwooleTest extends IntegrationTestCase
{
    /** Runs `$body` in a coroutine scheduler with all hooks on, the way winter-kernel does. */
    private function inCoroutines(\Closure $body): mixed
    {
        $servers = self::singleServers();   // started outside: proc_open is hooked inside
        $result = null;
        $error = null;
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            \Swoole\Coroutine\run(static function () use ($body, $servers, &$result, &$error): void {
                try {
                    $result = $body($servers);
                } catch (\Throwable $e) {
                    $error = $e;
                }
            });
        } finally {
            \Swoole\Runtime::enableCoroutine(0);
        }
        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    public function testSendAllUsesCoroutines(): void
    {
        [$responses, $elapsed] = $this->inCoroutines(static function (array $servers): array {
            $client = CastClient::create();
            $start = microtime(true);
            $responses = $client->sendAll(array_map(static fn($s) => $client->get($s . '/slow/400'), $servers));

            return [$responses, microtime(true) - $start];
        });

        self::assertCount(4, $responses);
        self::assertContainsOnlyInstancesOf(CastResponse::class, $responses);
        self::assertLessThan(0.75, $elapsed, sprintf('4 × 400 ms took %.2f s', $elapsed));
    }

    public function testOneClientSharedByCoroutines(): void
    {
        [$statuses, $elapsed] = $this->inCoroutines(static function (array $servers): array {
            $client = CastClient::create();
            $statuses = [];
            $group = new \Swoole\Coroutine\WaitGroup();
            $start = microtime(true);
            foreach ($servers as $i => $server) {
                $group->add();
                \Swoole\Coroutine::create(static function () use ($client, $server, $i, $group, &$statuses): void {
                    $statuses[$i] = $client->get($server . '/slow/300')->send()->getStatusCode()
                        + $client->get($server . '/echo')->send()->getStatusCode();
                    $group->done();
                });
            }
            $group->wait();
            ksort($statuses);

            return [$statuses, microtime(true) - $start];
        });

        self::assertSame([400, 400, 400, 400], array_values($statuses));
        self::assertLessThan(0.6, $elapsed, sprintf('took %.2f s', $elapsed));
    }

    public function testRetryInACoroutine(): void
    {
        $attempts = $this->inCoroutines(static function (array $servers): int {
            return CastClient::create()
                ->withRetry(RetryPolicy::exponential(3, 10)->withStatuses(503))
                ->get($servers[0] . '/flaky/' . bin2hex(random_bytes(4)) . '/2')
                ->send()
                ->info()
                ->attempts;
        });

        self::assertSame(3, $attempts);
    }
}
