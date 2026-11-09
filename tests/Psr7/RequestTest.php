<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Psr7;

use Flytachi\Winter\Cast\Message\CastRequest;
use Http\Psr7Test\RequestIntegrationTest;

/** PSR-7 conformance: php-http/psr7-integration-tests against CastRequest. */
class RequestTest extends RequestIntegrationTest
{
    public function createSubject()
    {
        return new CastRequest('GET', '/');
    }
}
