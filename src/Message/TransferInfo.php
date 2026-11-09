<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Message;

/**
 * What happened while the response was received — `$response->info()`.
 *
 * Times are seconds of the last attempt; `attempts` and `redirects` count the whole operation.
 */
final readonly class TransferInfo
{
    public function __construct(
        public float $totalTime = 0.0,
        public float $connectTime = 0.0,
        public float $startTransferTime = 0.0,
        public ?string $effectiveUri = null,
        public int $redirects = 0,
        public int $attempts = 1,
        public ?string $primaryIp = null,
        public int $downloadedBytes = 0,
        public int $uploadedBytes = 0,
        public string $protocol = '1.1',
    ) {
    }

    /**
     * @param array<string, mixed> $curl curl_getinfo() of the last attempt
     *
     * @internal
     */
    public static function fromCurl(array $curl, string $protocol): self
    {
        return new self(
            totalTime: (float) ($curl['total_time'] ?? 0.0),
            connectTime: (float) ($curl['connect_time'] ?? 0.0),
            startTransferTime: (float) ($curl['starttransfer_time'] ?? 0.0),
            effectiveUri: isset($curl['url']) ? (string) $curl['url'] : null,
            primaryIp: ($curl['primary_ip'] ?? '') !== '' ? (string) $curl['primary_ip'] : null,
            downloadedBytes: (int) ($curl['size_download'] ?? 0),
            uploadedBytes: (int) ($curl['size_upload'] ?? 0),
            protocol: $protocol,
        );
    }

    /** @internal */
    public function withCounts(int $attempts, int $redirects, ?string $effectiveUri): self
    {
        return new self(
            $this->totalTime,
            $this->connectTime,
            $this->startTransferTime,
            $effectiveUri ?? $this->effectiveUri,
            $redirects,
            $attempts,
            $this->primaryIp,
            $this->downloadedBytes,
            $this->uploadedBytes,
            $this->protocol,
        );
    }
}
