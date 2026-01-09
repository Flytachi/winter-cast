<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;

class CastException extends Exception
{
    protected string $logLevel = LogLevel::ALERT;
}
