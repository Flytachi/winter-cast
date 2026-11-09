<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Psr7;

use Flytachi\Winter\Cast\Message\CastStream;
use Http\Psr7Test\StreamIntegrationTest;
use Psr\Http\Message\StreamInterface;

/** PSR-7 conformance: php-http/psr7-integration-tests against CastStream. */
class StreamTest extends StreamIntegrationTest
{
    public function createStream($data)
    {
        if ($data instanceof StreamInterface) {
            return $data;
        }
        if (is_resource($data)) {
            return CastStream::fromResource($data);
        }

        return CastStream::fromString((string) $data);
    }
}
