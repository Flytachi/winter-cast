<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

use CurlHandle;
use Flytachi\Winter\Base\Log\LoggerRegistry;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Exception\ConnectionException;
use Flytachi\Winter\Cast\Exception\RequestException;
use Flytachi\Winter\Cast\Exception\TimeoutException;
use Psr\Log\LoggerInterface;

/**
 * The core HTTP client engine that executes requests using cURL.
 *
 * CastClient is responsible for the low-level execution of HTTP requests.
 * It initializes cURL handles, applies request configurations (headers, body,
 * timeouts), manages retry logic, and parses cURL responses into CastResponse objects.
 *
 * The client can be used directly for dependency injection in applications
 * requiring testability or custom configuration, or accessed globally via
 * the Cast facade for simple use cases.
 *
 * ---
 * ### Example 1: Using CastClient directly (Dependency Injection)
 *
 * ```
 * use Flytachi\Winter\Cast\Common\CastClient;
 * use Flytachi\Winter\Cast\Common\CastRequest;
 *
 * class ApiService {
 *     public function __construct(
 *         private readonly CastClient $httpClient
 *     ) {}
 *
 *     public function fetchUsers(): array {
 *         $request = CastRequest::get('https://api.com/users')
 *             ->timeout(5);
 *
 *         $response = $this->httpClient->send($request);
 *         return $response->json();
 *     }
 * }
 * ```
 *
 * ---
 * ### Example 2: Custom client configuration
 *
 * ```
 * // Create client with custom default timeouts
 * $client = new CastClient(
 *     defaultTimeout: 30,           // 30 seconds total timeout
 *     defaultConnectTimeout: 10,    // 10 seconds connection timeout
 *     defaultOptions: [
 *         CURLOPT_SSL_VERIFYPEER => false  // Disable SSL verification (dev only!)
 *     ]
 * );
 *
 * $response = CastRequest::get('https://api.com/data')
 *     ->send($client);
 * ```
 *
 * ---
 * ### Example 3: Multiple clients for different APIs
 *
 * ```
 * // Fast client for internal APIs
 * $internalClient = new CastClient(
 *     defaultTimeout: 5,
 *     defaultConnectTimeout: 2
 * );
 *
 * // Slow client for external APIs
 * $externalClient = new CastClient(
 *     defaultTimeout: 60,
 *     defaultConnectTimeout: 10
 * );
 *
 * $internalData = CastRequest::get('https://internal-api/data')
 *     ->send($internalClient);
 *
 * $externalData = CastRequest::get('https://external-api/data')
 *     ->send($externalClient);
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Common
 * @author Flytachi
 *
 * @see CastRequest
 * @see CastResponse
 * @see Cast
 */
class CastClient
{
    private LoggerInterface $logger;

    /**
     * @param int $defaultTimeout Default total timeout in seconds for requests.
     * @param int $defaultConnectTimeout Default connection timeout in seconds.
     * @param array $defaultOptions Default cURL options to apply to all requests.
     */
    public function __construct(
        private readonly int $defaultTimeout = 10,
        private readonly int $defaultConnectTimeout = 5,
        private readonly array $defaultOptions = []
    ) {
        $this->logger = LoggerRegistry::instance('CastClient');
    }

    /**
     * Executes the given HTTP request.
     *
     * @param CastRequest $request The request object to send.
     * @return CastResponse The response object.
     * @throws CastException If the request fails after all retry attempts.
     */
    public function send(CastRequest $request): CastResponse
    {
        $this->logger->debug('Sending request', [
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
            'timeout' => $request->getTimeout(),
        ]);
        $curlHandle = $this->initializeCurl($request);
        $lastException = null;

        $attempts = $request->getRetryCount();

        while ($attempts > 0) {
            $attempts--;
            $responseBody = null;
            $headerData = [];

            try {
                curl_setopt($curlHandle, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$headerData) {
                    $len = strlen($header);
                    $header = trim($header);
                    if ($len > 0 && str_contains($header, ':')) {
                        [$name, $value] = array_map('trim', explode(':', $header, 2));
                        if (isset($headerData[$name])) {
                            if (!is_array($headerData[$name])) {
                                $headerData[$name] = [$headerData[$name]];
                            }
                            $headerData[$name][] = $value;
                        } else {
                            $headerData[$name] = $value;
                        }
                    }
                    return $len;
                });

                $responseBody = curl_exec($curlHandle);

                if ($responseBody === false) {
                    $curlErrno = curl_errno($curlHandle);
                    $curlError = curl_error($curlHandle);

                    $this->logger->critical('Failed request', [
                        'method' => $request->getMethod(),
                        'url' => $request->getUrl(),
                        'curl_errno' => $curlErrno,
                        'curl_error' => $curlError,
                    ]);

                    throw match (true) {
                        // Timeout
                        in_array($curlErrno, [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED], true)
                            => new TimeoutException("Request timed out: {$curlError}", $curlErrno),

                        // Connection
                        in_array($curlErrno, [
                            CURLE_COULDNT_CONNECT,
                            CURLE_COULDNT_RESOLVE_HOST,
                            CURLE_COULDNT_RESOLVE_PROXY,
                            CURLE_GOT_NOTHING
                        ], true)
                            => new ConnectionException("Connection failed: {$curlError}", $curlErrno),

                        default => new CastException("cURL Error (errno {$curlErrno}): {$curlError}", $curlErrno)
                    };
                }

                $lastException = null;
                break;
            } catch (CastException $e) {
                $lastException = $e;
                if ($attempts > 0) {
                    $this->logger->debug('Failed request, retrying', [
                        'method' => $request->getMethod(),
                        'url' => $request->getUrl(),
                        'error' => $e->getMessage(),
                        'attempts_left' => $attempts,
                        'retry_delay' => $request->getRetryDelay(),
                    ]);
                    usleep($request->getRetryDelay() * 1000);
                    continue;
                }
            }
        }

        if ($lastException !== null) {
            curl_close($curlHandle);
            throw $lastException;
        }

        $info = curl_getinfo($curlHandle);
        $response = new CastResponse(
            statusCode: $info['http_code'] ?? 0,
            body: $responseBody ?? null,
            headers: $headerData ?? [],
            info: $info
        );
        $this->logger->debug('Completed request', [
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
            'status' => $response->statusCode,
            'duration' => $info['total_time'] ?? 0,
        ]);

        if ($request->shouldThrowOnError() && !$response->isSuccess()) {
            $this->logger->critical('Request returned error status', [
                'method' => $request->getMethod(),
                'url' => $request->getUrl(),
                'status' => $response->statusCode,
                'body_preview' => substr($response->body() ?? '', 0, 200),
            ]);
            $message = "HTTP Error {$response->statusCode}: " . ($response->body() ?? 'No response body');
            throw new RequestException($response, $message);
        }

        return $response;
    }

    /**
     * Initializes and configures a cURL handle based on the CastRequest.
     *
     * @param CastRequest $request
     * @return CurlHandle
     */
    private function initializeCurl(CastRequest $request): CurlHandle
    {
        $curlHandle = curl_init();

        $options = $this->defaultOptions + [
            CURLOPT_URL => $request->getUrl(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $request->getConnectTimeout() ?? $this->defaultConnectTimeout,
            CURLOPT_TIMEOUT => $request->getTimeout() ?? $this->defaultTimeout,
            CURLOPT_HTTPHEADER => $request->getHeaders()->toArray(),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_ENCODING => '',
        ];

        $maxSize = $request->getMaxResponseSize();
        if ($maxSize !== null) {
            $options[CURLOPT_MAXFILESIZE] = $maxSize;
        }

        $body = $request->getBody();
        if ($request->isMultipart()) {
            $options[CURLOPT_POSTFIELDS] = $body;
        } elseif ($body !== null && $body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $options += $request->getOptions();
        curl_setopt_array($curlHandle, $options);
        return $curlHandle;
    }
}
