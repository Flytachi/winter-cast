<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Root of every runtime error the library throws — catch it to catch them all.
 *
 * ```
 * CastException                 ClientExceptionInterface (PSR-18)
 * ├── RequestException          the request cannot be sent as it is
 * ├── NetworkException          no response: DNS, connection, TLS, broken transfer
 * │   └── TimeoutException
 * ├── TooLargeException         the response body exceeds maxResponseSize
 * ├── HttpException             a 4xx/5xx status — only via throw() / withThrowOnError()
 * │   ├── ClientErrorException
 * │   └── ServerErrorException
 * └── BatchException            sendAll(): some requests got no response
 * ```
 *
 * Invalid arguments to PSR-7 methods (a malformed header, URI or status) throw
 * {@see InvalidArgumentException}, as PSR-7 requires; it also implements
 * ClientExceptionInterface.
 */
class CastException extends \RuntimeException implements ClientExceptionInterface
{
}
