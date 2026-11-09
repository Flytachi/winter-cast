<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * An argument that can never be valid: a malformed header name or value, URI, status
 * code, method; a JSON body that cannot be encoded; a reserved cURL option.
 *
 * Extends SPL's InvalidArgumentException because PSR-7 requires exactly that from its
 * `with*()` methods, and implements PSR-18's ClientExceptionInterface so that catching
 * the client's exceptions catches it too.
 */
class InvalidArgumentException extends \InvalidArgumentException implements ClientExceptionInterface
{
}
