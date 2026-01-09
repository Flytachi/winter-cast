<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

use CurlHandle;
use Flytachi\Winter\Cast\Exception\CastException;

/**
 * Class CastClient
 *
 * The core engine for sending HTTP requests. This class is responsible for
 * executing a CastRequest, handling the underlying cURL operations,
 * managing retries, and constructing a CastResponse object.
 * It is designed to be used directly via Dependency Injection or through the Cast facade.
 *
 * @version 1.1
 * @author Flytachi
 */
class CastClient
{
    /**
     * @param int $defaultTimeout Default total timeout in seconds for requests.
     * @param int $defaultConnectTimeout Default connection timeout in seconds.
     * @param array $defaultOptions Default cURL options to apply to all requests.
     */
    public function __construct(
        private readonly int   $defaultTimeout = 10,
        private readonly int   $defaultConnectTimeout = 5,
        private readonly array $defaultOptions = []
    )
    {
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
        $curlHandle = $this->initializeCurl($request);
        $lastException = null;

        $attempts = $request->getRetryCount();

        while ($attempts > 0) {
            $attempts--;

            // These variables must be reset for each attempt
            $responseBody = null;
            $headerData = [];

            try {
                // Set up header capturing for each attempt
                curl_setopt($curlHandle, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$headerData) {
                    $len = strlen($header);
                    $header = trim($header);
                    if ($len > 0 && str_contains($header, ':')) {
                        [$name, $value] = array_map('trim', explode(':', $header, 2));
                        // Handle headers that may appear multiple times (e.g., Set-Cookie)
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

                // Check for cURL-level errors (network, DNS, timeout, etc.)
                if ($responseBody === false) {
                    $curlErrno = curl_errno($curlHandle);
                    $curlError = curl_error($curlHandle);
                    
                    // Throw specific exception based on error type
                    throw match (true) {
                        // Timeout errors
                        in_array($curlErrno, [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED], true) 
                            => new \Flytachi\Winter\Cast\Exception\TimeoutException("Request timed out: {$curlError}", $curlErrno),
                        
                        // Connection errors
                        in_array($curlErrno, [
                            CURLE_COULDNT_CONNECT, 
                            CURLE_COULDNT_RESOLVE_HOST, 
                            CURLE_COULDNT_RESOLVE_PROXY,
                            CURLE_GOT_NOTHING
                        ], true) 
                            => new \Flytachi\Winter\Cast\Exception\ConnectionException("Connection failed: {$curlError}", $curlErrno),
                        
                        // Generic error for everything else
                        default => new CastException("cURL Error (errno {$curlErrno}): {$curlError}", $curlErrno)
                    };
                }

                // If we are here, the request was successful at the transport level.
                // We can clear any previous exception and break the retry loop.
                $lastException = null;
                break;

            } catch (CastException $e) {
                $lastException = $e;
                // If there are attempts left, wait and retry.
                if ($attempts > 0) {
                    // Use usleep for millisecond precision; it takes microseconds.
                    usleep($request->getRetryDelay() * 1000);
                    continue;
                }
            }
        }

        // If all attempts failed, the loop finishes and $lastException will not be null.
        // We must close the handle and re-throw the exception.
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

        if ($request->shouldThrowOnError() && !$response->isSuccess()) {
            $message = "HTTP Error {$response->statusCode}: " . ($response->body() ?? 'No response body');
            throw new \Flytachi\Winter\Cast\Exception\RequestException($response, $message);
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
            CURLOPT_ENCODING => '', // Allow cURL to handle compressed responses (e.g., gzip)
        ];

        // Apply max response size limit if set
        $maxSize = $request->getMaxResponseSize();
        if ($maxSize !== null) {
            $options[CURLOPT_MAXFILESIZE] = $maxSize;
        }

        // Add body for relevant methods.
        // Note: GET requests can have a body, but it's non-standard. We allow it.
        $body = $request->getBody();
        if ($request->isMultipart()) {
            // cURL will see an array and automatically set the Content-Type to multipart/form-data
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        // For all other non-empty bodies (strings from json_encode, http_build_query, etc. )
        elseif ($body !== null && $body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        // Apply any extra options from the request, overriding defaults.
        $options += $request->getOptions();

        curl_setopt_array($curlHandle, $options);

        return $curlHandle;
    }
}
