<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Client;

use Flytachi\Winter\Cast\Exception\InvalidArgumentException;

/**
 * How long to wait — four separate questions:
 *
 * | field      | question                                              | default |
 * |------------|-------------------------------------------------------|---------|
 * | `connect`  | how long to wait for a connection                     | 5 s     |
 * | `total`    | how long one attempt may last (null — no limit)       | 30 s    |
 * | `idle`     | abort when data nearly stops flowing                  | off     |
 * | `deadline` | the limit of the whole operation, retries and pauses included | off |
 *
 * `total` applies to each attempt: with 30 s, three attempts and short pauses, a silent server
 * gives a TimeoutException after about 92 s. `deadline` bounds the whole operation — the last
 * attempt gets only what is left, and when nothing is left there is no retry.
 *
 * ```
 * Timeouts::of(total: 10, connect: 2);
 * Timeouts::forDownload();                       // no total limit, abort on a stall
 * Timeouts::of(total: 30)->withDeadline(45);     // an answer within 45 s, retries included
 * ```
 */
final readonly class Timeouts
{
    private function __construct(
        public float $connect,
        public ?float $total,
        public ?float $idle,
        public int $idleMinBytesPerSecond,
        public ?float $deadline,
    ) {
        $values = ['connect' => $connect, 'total' => $total, 'idle' => $idle, 'deadline' => $deadline];
        foreach ($values as $name => $value) {
            if ($value !== null && $value <= 0) {
                throw new InvalidArgumentException("Timeout '{$name}' must be positive, {$value} given");
            }
        }
        if ($idleMinBytesPerSecond < 1) {
            throw new InvalidArgumentException('idleMinBytesPerSecond must be at least 1');
        }
    }

    /**
     * Ordinary timeouts.
     *
     * @param float|null $total   seconds per attempt; null — no limit
     * @param float      $connect seconds to establish the connection
     */
    public static function of(?float $total = 30.0, float $connect = 5.0): self
    {
        return new self($connect, $total, null, 1, null);
    }

    /**
     * For a long download: no total limit (a big file takes as long as it takes), but abort when
     * less than `$minBytesPerSecond` arrives for `$idle` seconds.
     */
    public static function forDownload(float $idle = 60.0, int $minBytesPerSecond = 1024, float $connect = 5.0): self
    {
        return new self($connect, null, $idle, $minBytesPerSecond, null);
    }

    public function withConnect(float $seconds): self
    {
        return new self($seconds, $this->total, $this->idle, $this->idleMinBytesPerSecond, $this->deadline);
    }

    public function withTotal(?float $seconds): self
    {
        return new self($this->connect, $seconds, $this->idle, $this->idleMinBytesPerSecond, $this->deadline);
    }

    /** Abort when less than `$minBytesPerSecond` flows for `$seconds`; null — off. */
    public function withIdle(?float $seconds, int $minBytesPerSecond = 1): self
    {
        return new self($this->connect, $this->total, $seconds, $minBytesPerSecond, $this->deadline);
    }

    /** The limit of the whole operation, retries and pauses included; null — off. */
    public function withDeadline(?float $seconds): self
    {
        return new self($this->connect, $this->total, $this->idle, $this->idleMinBytesPerSecond, $seconds);
    }
}
