<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Psr7;

use Flytachi\Winter\Cast\Message\CastUri;
use Http\Psr7Test\UriIntegrationTest;

/** PSR-7 conformance: php-http/psr7-integration-tests against CastUri. */
class UriTest extends UriIntegrationTest
{
    public function createUri($uri)
    {
        return new CastUri($uri);
    }
}
