<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Flytachi\Winter\Cast\Message\CastResponse;

/**
 * sendAll(): some requests got no response. Thrown after every request has finished;
 * the responses that did arrive are not lost.
 */
class BatchException extends CastException
{
    /**
     * @param array<array-key, CastResponse> $responses by the keys the requests were given
     * @param array<array-key, \Throwable>   $failures  by the same keys
     */
    public function __construct(
        private readonly array $responses,
        private readonly array $failures,
    ) {
        $first = reset($failures);
        parent::__construct(
            sprintf(
                '%d of %d requests got no response; first: %s',
                count($failures),
                count($failures) + count($responses),
                $first instanceof \Throwable ? $first->getMessage() : 'unknown',
            ),
            0,
            $first instanceof \Throwable ? $first : null,
        );
    }

    /** @return array<array-key, CastResponse> */
    public function responses(): array
    {
        return $this->responses;
    }

    /** @return array<array-key, \Throwable> */
    public function failures(): array
    {
        return $this->failures;
    }
}
