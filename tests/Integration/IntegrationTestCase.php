<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Integration;

use Flytachi\Winter\Cast\Tests\TestCase;

/**
 * Starts the echo server (Server/router.php) on free ports for the whole run: one main server
 * with several workers, and a few single-worker ones — separate servers give exact timings
 * for concurrency tests, where the workers of one `php -S` do not.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var list<array{process: resource, port: int}> */
    private static array $servers = [];

    protected static function url(string $path = ''): string
    {
        return 'http://127.0.0.1:' . self::servers()[0]['port'] . '/' . ltrim($path, '/');
    }

    /** @return list<string> base URLs of the single-worker servers */
    protected static function singleServers(): array
    {
        return array_map(
            static fn(array $s): string => 'http://127.0.0.1:' . $s['port'],
            array_slice(self::servers(), 1),
        );
    }

    /** A port nothing listens on. */
    protected static function closedPort(): int
    {
        return self::freePort();
    }

    /** @return list<array{process: resource, port: int}> */
    private static function servers(): array
    {
        if (self::$servers === []) {
            self::$servers[] = self::start(8);
            for ($i = 0; $i < 4; $i++) {
                self::$servers[] = self::start(1);
            }
            register_shutdown_function(static function (): void {
                foreach (self::$servers as $server) {
                    proc_terminate($server['process']);
                    proc_close($server['process']);
                }
            });
        }

        return self::$servers;
    }

    /** @return array{process: resource, port: int} */
    private static function start(int $workers): array
    {
        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/Server/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => (string) $workers] + getenv(),
        );
        if (!is_resource($process)) {
            self::fail('Cannot start the echo server');
        }

        $until = microtime(true) + 5;
        while (microtime(true) < $until) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return ['process' => $process, 'port' => $port];
            }
            usleep(20_000);
        }
        self::fail("The echo server did not start on port {$port}");
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    protected function tempFile(string $content = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cast-test-');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }
}
