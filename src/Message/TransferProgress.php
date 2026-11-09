<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Message;

/**
 * Where a transfer stands — what an onProgress() callback receives.
 *
 * Totals are null while the server has not told the size (no Content-Length yet, or a
 * chunked response).
 */
final readonly class TransferProgress
{
    public function __construct(
        public int $downloaded,
        public ?int $downloadTotal,
        public int $uploaded,
        public ?int $uploadTotal,
    ) {
    }

    /** 0…100, or null while the size is unknown. */
    public function downloadPercent(): ?float
    {
        return $this->downloadTotal ? min(100.0, $this->downloaded * 100 / $this->downloadTotal) : null;
    }

    /** 0…100, or null while the size is unknown. */
    public function uploadPercent(): ?float
    {
        return $this->uploadTotal ? min(100.0, $this->uploaded * 100 / $this->uploadTotal) : null;
    }
}
