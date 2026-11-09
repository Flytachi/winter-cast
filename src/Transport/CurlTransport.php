<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Transport;

use Composer\InstalledVersions;
use Flytachi\Winter\Cast\Client\TransferOptions;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Flytachi\Winter\Cast\Exception\NetworkException;
use Flytachi\Winter\Cast\Exception\RequestException;
use Flytachi\Winter\Cast\Exception\TimeoutException;
use Flytachi\Winter\Cast\Exception\TooLargeException;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;
use Flytachi\Winter\Cast\Message\CastUri;
use Flytachi\Winter\Cast\Message\TransferInfo;
use Flytachi\Winter\Cast\Transport\Concurrency\Concurrency;

/**
 * The default transport: one exchange over cURL.
 *
 * - the method is set the way cURL expects it (HEAD with NOBODY, GET with HTTPGET, the rest
 *   with CUSTOMREQUEST), so a HEAD never waits for a body that will not come;
 * - only the final response's headers are kept (not those of a 100 Continue or a proxy);
 * - a request body up to 1 MiB is sent from memory, a larger or unknown-size one is streamed;
 * - a 2xx body goes to the sinkTo() target when there is one, anything else to memory, with
 *   maxResponseSize enforced;
 * - HTTP/2 over TLS when cURL and the server support it, HTTP/1.1 otherwise.
 *
 * @internal Construct it only to pass a custom pool; clients create their own.
 */
final class CurlTransport implements Transport
{
    /**
     * cURL options the library sets itself; withCurlOptions() refuses them, naming the method
     * that does the job instead.
     */
    public const array RESERVED = [
        CURLOPT_URL => 'the request URI',
        CURLOPT_CUSTOMREQUEST => 'withMethod()',
        CURLOPT_POST => 'withMethod()',
        CURLOPT_HTTPGET => 'withMethod()',
        CURLOPT_NOBODY => 'withMethod()',
        CURLOPT_HTTPHEADER => 'withHeader() / withHeaders()',
        CURLOPT_POSTFIELDS => 'withStringBody() / withJsonBody() / withFormBody() / withMultipartBody()',
        CURLOPT_UPLOAD => 'withFileBody() / withBody()',
        CURLOPT_INFILESIZE => 'withFileBody() / withBody()',
        CURLOPT_READFUNCTION => 'withFileBody() / withBody()',
        CURLOPT_WRITEFUNCTION => 'sinkTo()',
        CURLOPT_FILE => 'sinkTo()',
        CURLOPT_HEADERFUNCTION => 'the response headers',
        CURLOPT_RETURNTRANSFER => 'the response body',
        CURLOPT_XFERINFOFUNCTION => 'onProgress()',
        CURLOPT_NOPROGRESS => 'onProgress()',
        CURLOPT_FOLLOWLOCATION => 'withRedirects()',
        CURLOPT_PROTOCOLS => 'nothing — only http and https are allowed',
    ];

    /** A request body up to this size is sent from memory; larger ones are streamed. */
    private const int INLINE_BODY = 1024 * 1024;

    private const string PACKAGE = 'flytachi/winter-cast';

    private static ?string $userAgentSuffix = null;

    private readonly CurlHandlePool $pool;

    public function __construct(?CurlHandlePool $pool = null)
    {
        $this->pool = $pool ?? new CurlHandlePool();
    }

    /**
     * @param array<int, mixed> $options
     *
     * @throws InvalidArgumentException one of the options is reserved
     */
    public static function assertNotReserved(array $options): void
    {
        foreach ($options as $option => $_) {
            if (isset(self::RESERVED[$option])) {
                throw new InvalidArgumentException(sprintf(
                    'cURL option %d is set by the library and cannot be passed in withCurlOptions(); use %s',
                    $option,
                    self::RESERVED[$option],
                ));
            }
        }
    }

    /** `WinterCast/{version} curl/{version}`, built once per process. */
    public static function userAgentSuffix(): string
    {
        if (self::$userAgentSuffix !== null) {
            return self::$userAgentSuffix;
        }
        $version = 'dev';
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev';
        }
        $curl = curl_version()['version'] ?? 'unknown';

        return self::$userAgentSuffix = "WinterCast/{$version} curl/{$curl}";
    }

    public function execute(CastRequest $request, TransferOptions $options): CastResponse
    {
        $uri = $request->getUri();
        if (!in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new RequestException(
                "Only absolute http:// and https:// URIs can be sent, got '{$uri}'",
                $request,
            );
        }

        $transfer = new CurlTransfer($request, $options);
        $handle = $this->pool->acquire();
        $errno = -1;
        try {
            $curl = $this->curlOptions($request, $options, $transfer);
            curl_setopt_array($handle, $curl);
            $errno = Concurrency::perform($handle);
            if ($errno === 0 && !$transfer->failed()) {
                $transfer->finishSink();
            }
            $error = curl_error($handle);
            $info = curl_getinfo($handle);
        } finally {
            $transfer->closeSink();
            // After a failed transfer the handle is not reused: a fresh one is the safe bet.
            $this->pool->release($handle, reuse: $errno === 0);
        }

        if ($errno !== 0 || $transfer->failed()) {
            $transfer->discardSink();
            throw $this->failure($request, $options, $transfer, $errno, $error, $info);
        }
        if ($transfer->status === 0) {
            throw new NetworkException(
                'No HTTP response was received from ' . CastUri::parse($request->getUri())->redacted(),
                $request,
            );
        }

        $response = new CastResponse(
            $transfer->status,
            self::decodedHeaders($transfer->headers(), ($curl[CURLOPT_ACCEPT_ENCODING] ?? null) !== null),
            $transfer->responseBody(),
            $transfer->protocol,
            $transfer->reason !== '' ? $transfer->reason : null,
        );

        return $response->withTransfer(
            $request,
            TransferInfo::fromCurl($info, $transfer->protocol),
            $transfer->sunk,
        );
    }

    /** @return array<int, mixed> */
    private function curlOptions(CastRequest $request, TransferOptions $options, CurlTransfer $transfer): array
    {
        $method = strtoupper($request->getMethod());
        $https = $request->getUri()->getScheme() === 'https';
        $http2 = (curl_version()['features'] & CURL_VERSION_HTTP2) !== 0;

        // 1. library defaults a user may change through withCurlOptions()
        $curl = [
            CURLOPT_HTTP_VERSION => $http2 && $https ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
        ];

        // 2. the user's cURL options (client's, then request's — already merged)
        $curl = array_replace($curl, $options->curl);

        // 3. settings with a method of their own win over raw cURL options
        $timeouts = $options->timeouts;
        $curl[CURLOPT_CONNECTTIMEOUT_MS] = (int) ceil($timeouts->connect * 1000);
        $curl[CURLOPT_TIMEOUT_MS] = $timeouts->total === null ? 0 : (int) ceil($timeouts->total * 1000);
        if ($timeouts->idle !== null) {
            $curl[CURLOPT_LOW_SPEED_LIMIT] = $timeouts->idleMinBytesPerSecond;
            $curl[CURLOPT_LOW_SPEED_TIME] = max(1, (int) ceil($timeouts->idle));
        }

        // 4. mechanics: nobody overrides these
        $curl[CURLOPT_URL] = (string) $request->getUri();
        $curl[CURLOPT_FOLLOWLOCATION] = false;
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $curl[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $curl[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        $curl[CURLOPT_HEADERFUNCTION] = $transfer->onHeader(...);
        $curl[CURLOPT_WRITEFUNCTION] = $transfer->onWrite(...);

        if ($options->onProgress !== null) {
            $curl[CURLOPT_NOPROGRESS] = false;
            $curl[CURLOPT_XFERINFOFUNCTION] = $transfer->onProgress(...);
        }

        $headers = $this->headerLines($request, $options, $curl);
        $this->body($request, $method, $curl, $headers);
        $curl[CURLOPT_HTTPHEADER] = $headers;

        return $curl;
    }

    /**
     * @param array<int, mixed> $curl
     * @return list<string>
     */
    private function headerLines(CastRequest $request, TransferOptions $options, array &$curl): array
    {
        $lines = [];
        $ownEncoding = false;
        $multipart = self::curlMultipart($request);

        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, ['content-length', 'expect', 'user-agent'], true)) {
                continue; // set below or by cURL itself
            }
            if ($lower === 'content-type' && $multipart) {
                continue; // cURL writes its own boundary
            }
            if ($lower === 'accept-encoding') {
                $ownEncoding = true;
            }
            $lines[] = $name . ': ' . implode($lower === 'cookie' ? '; ' : ', ', $values);
        }

        $agent = trim($request->getHeaderLine('User-Agent'));
        $lines[] = 'User-Agent: ' . match (true) {
            $agent === '' => self::userAgentSuffix(),
            $options->userAgentExact, str_contains($agent, 'WinterCast/') => $agent,
            default => $agent . ' ' . self::userAgentSuffix(),
        };
        $lines[] = 'Expect:';   // no 100-continue round trip

        if (!$ownEncoding) {
            $curl[CURLOPT_ACCEPT_ENCODING] = '';   // gzip/deflate/br, decoded transparently
        }

        return $lines;
    }

    /**
     * @param array<int, mixed> $curl
     * @param list<string>      $headers
     */
    private function body(CastRequest $request, string $method, array &$curl, array &$headers): void
    {
        if ($method === 'HEAD') {
            $curl[CURLOPT_NOBODY] = true;
            return;
        }

        if (self::curlMultipart($request)) {
            $multipart = $request->getMultipart();
            $curl[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
            $curl[CURLOPT_POSTFIELDS] = $multipart->toCurlFields();
            return;
        }

        $body = $request->getBody();
        $size = $body->getSize();

        if ($size === 0 || !$body->isReadable()) {
            if ($method === 'GET') {
                $curl[CURLOPT_HTTPGET] = true;
            } else {
                $curl[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
                if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                    $curl[CURLOPT_POSTFIELDS] = '';   // Content-Length: 0
                }
            }
            return;
        }

        $curl[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($size !== null && $size <= self::INLINE_BODY) {
            $curl[CURLOPT_POSTFIELDS] = $body->getContents();
            return;
        }

        // Large or of unknown size: stream it, never holding it whole in memory.
        $curl[CURLOPT_UPLOAD] = true;
        $curl[CURLOPT_READFUNCTION] = static fn($handle, $fd, int $length): string
            => $body->eof() ? '' : $body->read($length);
        if ($size !== null) {
            $curl[CURLOPT_INFILESIZE_LARGE] = $size;
        } else {
            $headers[] = 'Transfer-Encoding: chunked';
        }
    }

    /**
     * Whether a multipart body goes to cURL as fields (files streamed by cURL). Parts that share
     * a name (`files[]`) cannot — a PHP array has one value per key — so such a body is rendered
     * with Multipart::toStream() and sent as a stream.
     */
    private static function curlMultipart(CastRequest $request): bool
    {
        $multipart = $request->getMultipart();

        return $multipart !== null && !$multipart->hasRepeatedNames();
    }

    /**
     * cURL has decoded a gzip/deflate/br body: its Content-Encoding and Content-Length describe
     * the bytes on the wire, not the body handed over. They are kept as X-Encoded-* headers.
     *
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private static function decodedHeaders(array $headers, bool $decoding): array
    {
        if (!$decoding) {
            return $headers;
        }
        $encoding = null;
        foreach ($headers as $name => $values) {
            if (strtolower($name) === 'content-encoding') {
                $encoding = $name;
            }
        }
        if ($encoding === null || strtolower(implode(',', $headers[$encoding])) === 'identity') {
            return $headers;
        }
        $headers['X-Encoded-Content-Encoding'] = $headers[$encoding];
        unset($headers[$encoding]);
        foreach ($headers as $name => $values) {
            if (strtolower($name) === 'content-length') {
                $headers['X-Encoded-Content-Length'] = $values;
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    /** @param array<string, mixed> $info */
    private function failure(
        CastRequest $request,
        TransferOptions $options,
        CurlTransfer $transfer,
        int $errno,
        string $error,
        array $info,
    ): CastException {
        if ($transfer->tooLarge) {
            return new TooLargeException(
                sprintf(
                    'The response body of %s exceeds %d bytes; raise withMaxResponseSize() or stream it with sinkTo()',
                    CastUri::parse($request->getUri())->redacted(),
                    (int) $options->maxResponseSize,
                ),
                $request,
                (int) $options->maxResponseSize,
            );
        }
        if ($transfer->sinkError !== null) {
            return new RequestException($transfer->sinkError, $request);
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            $connected = (float) ($info['connect_time'] ?? 0.0) > 0.0;

            return new TimeoutException(
                sprintf(
                    '%s %s timed out: %s',
                    $request->getMethod(),
                    CastUri::parse($request->getUri())->redacted(),
                    $error,
                ),
                $request,
                connect: !$connected,
            );
        }

        return new NetworkException(
            sprintf(
                '%s %s failed: %s',
                $request->getMethod(),
                CastUri::parse($request->getUri())->redacted(),
                $error !== '' ? $error : curl_strerror($errno),
            ),
            $request,
            $errno,
        );
    }
}
