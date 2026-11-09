<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Psr7;

use Flytachi\Winter\Cast\Message\CastResponse;
use Http\Psr7Test\ResponseIntegrationTest;

/** PSR-7 conformance: php-http/psr7-integration-tests against CastResponse. */
class ResponseTest extends ResponseIntegrationTest
{
    public function createSubject()
    {
        return new CastResponse();
    }
}
