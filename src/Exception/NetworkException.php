<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * No response was received: the host was not resolved, the connection was refused or
 * dropped, TLS failed, the transfer broke off.
 */
class NetworkException extends CastException implements NetworkExceptionInterface
{
    /**
     * cURL errors that are worth retrying: the next attempt may well succeed.
     * Not here on purpose: an unknown host, TLS verification failures, a malformed URL.
     */
    private const array TRANSIENT = [
        CURLE_COULDNT_CONNECT,
        CURLE_OPERATION_TIMEDOUT,
        CURLE_GOT_NOTHING,
        CURLE_SEND_ERROR,
        CURLE_RECV_ERROR,
        CURLE_PARTIAL_FILE,
        16,   // CURLE_HTTP2 — no PHP constant
        92,   // CURLE_HTTP2_STREAM — no PHP constant
    ];

    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        private readonly ?int $curlErrno = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $curlErrno ?? 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /** The cURL error number, for diagnostics; null when the failure did not come from cURL. */
    public function getCurlErrno(): ?int
    {
        return $this->curlErrno;
    }

    /** Whether a retry may succeed — what RetryPolicy retries by default. */
    public function isTransient(): bool
    {
        return $this->curlErrno !== null && in_array($this->curlErrno, self::TRANSIENT, true);
    }
}
