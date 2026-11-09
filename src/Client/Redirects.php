<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Client;

use Flytachi\Winter\Cast\Exception\InvalidArgumentException;

/**
 * Whether and how far to follow redirects. Followed like browsers do (RFC 9110): 303 turns
 * into GET without a body, 301/302 after POST into GET, 307/308 keep the method and the body.
 * Authorization and Cookie are not carried to another host; https → http is refused unless
 * allowed.
 */
final readonly class Redirects
{
    private function __construct(
        public int $max,
        public bool $allowDowngrade,
    ) {
        if ($max < 0) {
            throw new InvalidArgumentException('The number of redirects must not be negative');
        }
    }

    /** Follow up to `$max` redirects (10 by default). */
    public static function follow(int $max = 10, bool $allowDowngrade = false): self
    {
        return new self($max, $allowDowngrade);
    }

    /** Do not follow: a 3xx response is returned as it is. */
    public static function none(): self
    {
        return new self(0, false);
    }

    public function enabled(): bool
    {
        return $this->max > 0;
    }
}
