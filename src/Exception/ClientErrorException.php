<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

/** A 4xx response, thrown by throw() / withThrowOnError(). */
class ClientErrorException extends HttpException
{
}
