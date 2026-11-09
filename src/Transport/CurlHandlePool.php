<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Transport;

use CurlHandle;
use CurlShareHandle;

/**
 * Reuses cURL handles — and with them open connections — instead of creating one per request.
 *
 * A handle belongs to one transfer at a time: acquire() hands it out exclusively and release()
 * takes it back, so concurrent requests (Swoole coroutines, fibers) never share one. Swoole
 * runs coroutines on one thread, so taking from and putting back into the pool cannot
 * interleave mid-way.
 *
 * DNS answers and TLS sessions are shared between the handles (curl_share), so a new handle
 * to a known host skips the lookup and the full TLS handshake.
 *
 * @internal
 */
final class CurlHandlePool
{
    /** @var list<CurlHandle> */
    private array $idle = [];

    private ?CurlShareHandle $share = null;

    public function __construct(private readonly int $maxIdle = 16)
    {
        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        $this->share = $share;
    }

    public function acquire(): CurlHandle
    {
        $handle = array_pop($this->idle) ?? curl_init();
        if ($this->share !== null) {
            curl_setopt($handle, CURLOPT_SHARE, $this->share);
        }

        return $handle;
    }

    /**
     * Takes a handle back: its options are reset, its connection stays open for the next request.
     * With `$reuse` false (the transfer failed) the handle is dropped, with whatever state it
     * was left in.
     */
    public function release(CurlHandle $handle, bool $reuse = true): void
    {
        if (!$reuse || count($this->idle) >= $this->maxIdle) {
            return; // dropped: the handle and its connection are freed with it
        }
        curl_reset($handle);
        $this->idle[] = $handle;
    }
}
