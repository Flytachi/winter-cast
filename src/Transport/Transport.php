<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Transport;

use Flytachi\Winter\Cast\Client\TransferOptions;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;

/**
 * How one request goes over the wire — one exchange, nothing more: redirects, retries and
 * middleware are the client's business.
 *
 * The default is CurlTransport; MockTransport answers from a queue in tests.
 */
interface Transport
{
    /**
     * @throws CastException NetworkException, TimeoutException, TooLargeException, RequestException
     */
    public function execute(CastRequest $request, TransferOptions $options): CastResponse;
}
