<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

/** A 5xx response, thrown by throw() / withThrowOnError(). */
class ServerErrorException extends HttpException
{
}
