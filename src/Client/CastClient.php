<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Client;

use Flytachi\Winter\Base\Method;
use Flytachi\Winter\Cast\Exception\BatchException;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Exception\HttpException;
use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Flytachi\Winter\Cast\Exception\NetworkException;
use Flytachi\Winter\Cast\Exception\RequestException;
use Flytachi\Winter\Cast\Exception\TimeoutException;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;
use Flytachi\Winter\Cast\Message\CastStream;
use Flytachi\Winter\Cast\Message\CastUri;
use Flytachi\Winter\Cast\Middleware\CallableMiddleware;
use Flytachi\Winter\Cast\Middleware\CastMiddleware;
use Flytachi\Winter\Cast\Transport\Concurrency\Concurrency;
use Flytachi\Winter\Cast\Transport\CurlTransport;
use Flytachi\Winter\Cast\Transport\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;

/**
 * The HTTP client: a base address, default headers, timeouts, retries, middleware — set once,
 * used for every request. A PSR-18 client.
 *
 * ```
 * $api = CastClient::create()
 *     ->withBaseUrl('https://api.example.com/v1')
 *     ->withBearerToken($token)
 *     ->withRetry(RetryPolicy::exponential());
 *
 * $user = $api->get('users/42')->send()->throw()->json();
 * ```
 *
 * Immutable: every `with*()` returns a copy, so one instance can be a shared bean and be used
 * by many coroutines at once. Copies share the connection pool.
 */
final class CastClient implements ClientInterface
{
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private ?CastUri $baseUrl = null;
    /** The default headers, kept in a request so they are validated the same way. */
    private CastRequest $defaults;
    private bool $userAgentExact = false;
    private Timeouts $timeouts;
    private RetryPolicy $retry;
    private Redirects $redirects;
    private int $concurrency = 16;
    private ?int $maxResponseSize = 10 * 1024 * 1024;
    private bool $throwOnError = false;
    /** @var list<CastMiddleware> */
    private array $middleware = [];
    /** @var array<int, mixed> */
    private array $curl = [];
    private ?LoggerInterface $logger = null;
    private Transport $transport;

    public function __construct()
    {
        $this->defaults = new CastRequest('GET', '');
        $this->timeouts = Timeouts::of();
        $this->retry = RetryPolicy::none();
        $this->redirects = Redirects::follow();
        $this->transport = new CurlTransport();
    }

    public static function create(): self
    {
        return new self();
    }

    // ── Configuration ────────────────────────────────────────────────────────

    /**
     * The address relative paths of requests are appended to:
     * `https://api.example.com/v1` + `users/42` → `https://api.example.com/v1/users/42`.
     *
     * @throws InvalidArgumentException not an absolute http(s) URL
     */
    public function withBaseUrl(string|UriInterface $url): self
    {
        $uri = CastUri::parse($url);
        if (!$uri->isAbsolute() || !in_array($uri->getScheme(), ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                "The base URL must be an absolute http:// or https:// URL, got '{$url}'",
            );
        }

        return $this->with('baseUrl', $uri);
    }

    /** @param string|list<string> $value */
    public function withHeader(string $name, string|array $value): self
    {
        return $this->with('defaults', $this->defaults->withHeader($name, $value));
    }

    /**
     * Adds default headers; ones with the same names are replaced, the others stay.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function withHeaders(array $headers): self
    {
        return $this->with('defaults', $this->defaults->withHeaders($headers));
    }

    /** @param string|list<string> $value */
    public function withAddedHeader(string $name, string|array $value): self
    {
        return $this->with('defaults', $this->defaults->withAddedHeader($name, $value));
    }

    public function withoutHeader(string $name): self
    {
        return $this->with('defaults', $this->defaults->withoutHeader($name));
    }

    public function withBearerToken(string $token): self
    {
        return $this->with('defaults', $this->defaults->withBearerToken($token));
    }

    public function withBasicAuth(string $user, string $password): self
    {
        return $this->with('defaults', $this->defaults->withBasicAuth($user, $password));
    }

    public function withAccept(string ...$types): self
    {
        return $this->with('defaults', $this->defaults->withAccept(...$types));
    }

    /**
     * The application's User-Agent. `WinterCast/{version} curl/{version}` is appended to it —
     * `myapp/1.0 WinterCast/2.0.0 curl/8.11.0` — unless `$exact`.
     */
    public function withUserAgent(string $agent, bool $exact = false): self
    {
        $new = $this->with('defaults', $this->defaults->withHeader('User-Agent', $agent));
        $new->userAgentExact = $exact;

        return $new;
    }

    public function withTimeouts(Timeouts $timeouts): self
    {
        return $this->with('timeouts', $timeouts);
    }

    /** Seconds for one attempt and, when given, for connecting. */
    public function withTimeout(float $total, ?float $connect = null): self
    {
        $timeouts = $this->timeouts->withTotal($total);

        return $this->with('timeouts', $connect === null ? $timeouts : $timeouts->withConnect($connect));
    }

    public function withRetry(RetryPolicy $policy): self
    {
        return $this->with('retry', $policy);
    }

    public function withRedirects(Redirects $redirects): self
    {
        return $this->with('redirects', $redirects);
    }

    /** How many requests sendAll() runs at the same time (16 by default). */
    public function withConcurrency(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("The concurrency limit must be at least 1, got {$limit}");
        }

        return $this->with('concurrency', $limit);
    }

    /** The largest response body kept in memory (10 MiB by default); null — no limit. */
    public function withMaxResponseSize(?int $bytes): self
    {
        if ($bytes !== null && $bytes < 0) {
            throw new InvalidArgumentException("The response size limit cannot be negative, got {$bytes}");
        }

        return $this->with('maxResponseSize', $bytes);
    }

    /** send() throws HttpException on 4xx/5xx. sendRequest() never does — that is PSR-18. */
    public function withThrowOnError(bool $throw = true): self
    {
        return $this->with('throwOnError', $throw);
    }

    /**
     * Adds middleware to the end of the chain: the first added runs first (outermost).
     *
     * @param CastMiddleware|callable(CastRequest, callable(CastRequest): CastResponse): CastResponse ...$middleware
     */
    public function withMiddleware(CastMiddleware|callable ...$middleware): self
    {
        $list = $this->middleware;
        foreach ($middleware as $item) {
            $list[] = $item instanceof CastMiddleware ? $item : new CallableMiddleware($item(...));
        }

        return $this->with('middleware', $list);
    }

    /** Removes the middleware of class `$class` (and its subclasses). */
    public function withoutMiddleware(string $class): self
    {
        return $this->with('middleware', array_values(array_filter(
            $this->middleware,
            static fn(CastMiddleware $m): bool => !$m instanceof $class,
        )));
    }

    /**
     * cURL options for every request: a proxy, TLS settings… Options the library itself
     * relies on are refused — see CurlTransport::RESERVED.
     *
     * @param array<int, mixed> $options
     */
    public function withCurlOptions(array $options): self
    {
        CurlTransport::assertNotReserved($options);

        return $this->with('curl', array_replace($this->curl, $options));
    }

    /**
     * Logs a line per request (debug), each retry (warning) and a failure after all attempts
     * (error). Never headers or bodies, and no query strings.
     */
    public function withLogger(?LoggerInterface $logger): self
    {
        return $this->with('logger', $logger);
    }

    /** Another transport — MockTransport in tests. */
    public function withTransport(Transport $transport): self
    {
        return $this->with('transport', $transport);
    }

    // ── Reading the configuration ───────────────────────────────────────────

    public function getBaseUrl(): ?CastUri
    {
        return $this->baseUrl;
    }

    /** @return array<string, list<string>> */
    public function getHeaders(): array
    {
        return $this->defaults->getHeaders();
    }

    public function getTimeouts(): Timeouts
    {
        return $this->timeouts;
    }

    public function getRetryPolicy(): RetryPolicy
    {
        return $this->retry;
    }

    public function getRedirects(): Redirects
    {
        return $this->redirects;
    }

    /** @return list<CastMiddleware> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    // ── Requests bound to this client ────────────────────────────────────────

    /** @param array<string, mixed> $query */
    public function get(string|UriInterface $uri, array $query = []): CastRequest
    {
        return CastRequest::get($uri, $query)->bindTo($this);
    }

    /** @param array<string, mixed> $query */
    public function post(string|UriInterface $uri, array $query = []): CastRequest
    {
        return CastRequest::post($uri, $query)->bindTo($this);
    }

    /** @param array<string, mixed> $query */
    public function put(string|UriInterface $uri, array $query = []): CastRequest
    {
        return CastRequest::put($uri, $query)->bindTo($this);
    }

    /** @param array<string, mixed> $query */
    public function patch(string|UriInterface $uri, array $query = []): CastRequest
    {
        return CastRequest::patch($uri, $query)->bindTo($this);
    }

    /** @param array<string, mixed> $query */
    public function delete(string|UriInterface $uri, array $query = []): CastRequest
    {
        return CastRequest::delete($uri, $query)->bindTo($this);
    }

    /** @param array<string, mixed> $query */
    public function head(string|UriInterface $uri, array $query = []): CastRequest
    {
        return CastRequest::head($uri, $query)->bindTo($this);
    }

    /** @param array<string, mixed> $query */
    public function options(string|UriInterface $uri, array $query = []): CastRequest
    {
        return CastRequest::options($uri, $query)->bindTo($this);
    }

    public function request(Method|string $method, string|UriInterface $uri): CastRequest
    {
        return (new CastRequest($method instanceof Method ? $method->name : $method, $uri))->bindTo($this);
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    /**
     * Sends the request; 4xx/5xx are responses unless withThrowOnError() is on.
     *
     * @throws CastException     the request could not be sent or got no response
     * @throws HttpException     4xx/5xx with withThrowOnError()
     */
    public function send(RequestInterface $request): CastResponse
    {
        $request = $this->prepare($request);
        $response = $this->dispatch($request);

        if ($response->getStatusCode() >= 400 && $this->transferOptions($request)->throwOnError) {
            throw HttpException::from($response, $response->getRequest() ?? $request);
        }

        return $response;
    }

    /**
     * PSR-18: the same as send(), but 4xx/5xx are always responses.
     *
     * @throws CastException the request could not be sent or got no response
     */
    public function sendRequest(RequestInterface $request): CastResponse
    {
        return $this->dispatch($this->prepare($request));
    }

    /**
     * Sends requests at the same time, at most `$concurrency` at once; each passes the whole
     * chain (middleware, retries, redirects). A generator is read as sending goes on.
     *
     * @template TKey of array-key
     * @param iterable<TKey, RequestInterface> $requests
     * @return array<TKey, CastResponse> responses with the requests' keys, in their order;
     *         4xx/5xx included
     *
     * @throws BatchException some requests got no response — after all have finished
     */
    public function sendAll(iterable $requests, ?int $concurrency = null): array
    {
        $tasks = (function () use ($requests): \Generator {
            foreach ($requests as $key => $request) {
                yield $key => fn(): CastResponse => $this->sendRequest($request);
            }
        })();

        [$results, $failures, $order] = Concurrency::run($tasks, $concurrency ?? $this->concurrency);

        $responses = [];
        $failed = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $results)) {
                $responses[$key] = $results[$key];
            } elseif (isset($failures[$key])) {
                $failed[$key] = $failures[$key];
            }
        }
        if ($failed !== []) {
            throw new BatchException($responses, $failed);
        }

        return $responses;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl === null ? null : $this->baseUrl->redacted(),
            'headers' => $this->defaults->__debugInfo()['headers'],
            'timeouts' => $this->timeouts,
            'retry' => $this->retry,
            'redirects' => $this->redirects,
            'middleware' => array_map(static fn(CastMiddleware $m): string => $m::class, $this->middleware),
            'transport' => $this->transport::class,
        ];
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    /** The base URL resolved, the default headers under the request's own, bound to this client. */
    private function prepare(RequestInterface $request): CastRequest
    {
        $request = CastRequest::from($request);

        $uri = CastUri::parse($request->getUri());
        if (!$uri->isAbsolute()) {
            if ($this->baseUrl === null) {
                throw new RequestException(
                    "'{$uri}' is not an absolute URL and the client has no base URL (withBaseUrl())",
                    $request,
                );
            }
            $request = $request->withUri($this->baseUrl->resolve($uri));
        }

        foreach ($this->defaults->getHeaders() as $name => $values) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeader($name, $values);
            }
        }
        if ($this->userAgentExact && !array_key_exists('userAgentExact', $request->transferOptions())) {
            $request = $request->withUserAgent($request->getHeaderLine('User-Agent'), true);
        }

        return $request->bindTo($this);
    }

    private function dispatch(CastRequest $request): CastResponse
    {
        $next = $this->exchange(...);
        foreach (array_reverse($this->middleware) as $middleware) {
            $next = static fn(CastRequest $request): CastResponse => $middleware->process($request, $next);
        }

        return $next($request);
    }

    /** The client's settings with the request's on top. */
    private function transferOptions(CastRequest $request): TransferOptions
    {
        return new TransferOptions(
            timeouts: $this->timeouts,
            retry: $this->retry,
            redirects: $this->redirects,
            maxResponseSize: $this->maxResponseSize,
            throwOnError: $this->throwOnError,
            userAgentExact: false,
            curl: $this->curl,
        )->merge($request->transferOptions());
    }

    /** The innermost step of the chain: redirects, and retries of every hop. */
    private function exchange(CastRequest $request): CastResponse
    {
        $uri = $request->getUri();
        if (!in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new RequestException("Only absolute http:// and https:// URLs can be sent, got '{$uri}'", $request);
        }

        $options = $this->transferOptions($request);
        $started = hrtime(true);
        $deadlineAt = $options->timeouts->deadline === null
            ? null
            : $started + (int) ($options->timeouts->deadline * 1e9);
        $attempts = 0;
        $redirects = 0;

        try {
            $response = $this->attempts($request, $options, $deadlineAt, $attempts);

            while (
                $options->redirects->enabled()
                && in_array($response->getStatusCode(), self::REDIRECT_STATUSES, true)
                && $response->hasHeader('Location')
            ) {
                $next = $this->redirect($request, $response, $options->redirects);
                if ($next === null) {
                    break;
                }
                if (++$redirects > $options->redirects->max) {
                    throw new RequestException(
                        sprintf(
                            'Too many redirects (more than %d) from %s',
                            $options->redirects->max,
                            CastUri::parse($uri)->redacted(),
                        ),
                        $request,
                    );
                }
                $request = $next;
                $response = $this->attempts($request, $options, $deadlineAt, $attempts);
            }
        } catch (CastException $e) {
            $this->log('error', '{method} {uri} failed after {attempts} attempt(s): {error}', [
                'method' => $request->getMethod(),
                'uri' => CastUri::parse($request->getUri())->redacted(),
                'attempts' => $attempts,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            throw $e;
        }

        $response = $response->withInfo($response->info()->withCounts(
            $attempts,
            $redirects,
            (string) $request->getUri(),
        ));

        $this->log('debug', '{method} {uri} → {status} ({ms} ms{extra})', [
            'method' => $request->getMethod(),
            'uri' => CastUri::parse($request->getUri())->redacted(),
            'status' => $response->getStatusCode(),
            'ms' => (int) round((hrtime(true) - $started) / 1e6),
            'extra' => ($attempts > 1 + $redirects ? ', ' . $attempts . ' attempts' : '')
                . ($redirects > 0 ? ', ' . $redirects . ' redirect(s)' : ''),
        ]);

        return $response;
    }

    /** One hop: the first attempt and the retries the policy allows. */
    private function attempts(
        CastRequest $request,
        TransferOptions $options,
        ?int $deadlineAt,
        int &$total,
    ): CastResponse {
        $policy = $options->retry;
        $method = $request->getMethod();
        $repeatable = $policy->enabled() && self::repeatable($request, $options->sink);
        $sinkStart = $repeatable ? self::sinkPosition($options->sink) : null;

        for ($attempt = 1;; $attempt++) {
            $total++;
            $attemptOptions = $options;
            if ($deadlineAt !== null) {
                $left = ($deadlineAt - hrtime(true)) / 1e9;
                if ($left <= 0) {
                    throw $this->deadlineExceeded($request, $options);
                }
                $attemptOptions = $options->withAttemptLimit($left);
            }
            if ($attempt > 1) {
                self::rewind($request, $options->sink, $sinkStart);
            }

            try {
                $response = $this->transport->execute($request, $attemptOptions);
            } catch (NetworkException $e) {
                if ($deadlineAt !== null && $e instanceof TimeoutException && hrtime(true) >= $deadlineAt - 5_000_000) {
                    throw $this->deadlineExceeded($request, $options, $e);
                }
                if (!$repeatable || !$policy->retriesFailure($method, $attempt, $e)) {
                    throw $e;
                }
                $delay = $policy->delayAfter($attempt);
                if (!$this->fitsDeadline($delay, $deadlineAt)) {
                    throw $this->deadlineExceeded($request, $options, $e);
                }
                $this->logRetry($request, $attempt, $e->getMessage(), $delay);
                Concurrency::sleep($delay);
                continue;
            }

            if ($repeatable && $policy->retriesResponse($method, $attempt, $response)) {
                $delay = $policy->delayAfter($attempt, $response);
                if ($this->fitsDeadline($delay, $deadlineAt)) {
                    $this->logRetry($request, $attempt, 'HTTP ' . $response->getStatusCode(), $delay);
                    Concurrency::sleep($delay);
                    continue;
                }
            }

            return $response;
        }
    }

    /**
     * The request for the Location of a 3xx, the way browsers do it (RFC 9110 §15.4); null when
     * it must not be followed (https → http, a body that cannot be sent again).
     */
    private function redirect(CastRequest $request, CastResponse $response, Redirects $redirects): ?CastRequest
    {
        $from = $request->getUri();
        $to = self::resolveLocation($from, $response->getHeaderLine('Location'));
        if (!in_array($to->getScheme(), ['http', 'https'], true) || $to->getHost() === '') {
            return null;
        }
        if ($from->getScheme() === 'https' && $to->getScheme() === 'http' && !$redirects->allowDowngrade) {
            return null;
        }

        $status = $response->getStatusCode();
        $method = $request->getMethod();
        $toGet = ($status === 303 && $method !== 'HEAD')
            || (in_array($status, [301, 302], true) && $method === 'POST');

        if ($toGet) {
            $request = $request->withMethod('GET')
                ->withBody(CastStream::fromString())
                ->withoutHeader('Content-Type')
                ->withoutHeader('Content-Length')
                ->withoutHeader('Transfer-Encoding');
        } elseif (!$request->rewindBody()) {
            return null;
        }

        if (self::origin($from) !== self::origin($to)) {
            foreach ($request->secretHeaders() as $header) {
                $request = $request->withoutHeader($header);
            }
        }

        return $request->withUri($to);
    }

    /** RFC 3986 §5.2 — how a Location is resolved (unlike the base URL, which is appended to). */
    private static function resolveLocation(UriInterface $base, string $location): CastUri
    {
        $ref = CastUri::parse(trim($location));
        if ($ref->getScheme() !== '') {
            return $ref->withPath(self::removeDots($ref->getPath()));
        }
        $base = CastUri::parse($base);
        if ($ref->getAuthority() !== '') {
            return $ref->withScheme($base->getScheme());
        }

        $path = $ref->getPath();
        $query = $ref->getQuery();
        if ($path === '') {
            $path = $base->getPath();
            if ($query === '') {
                $query = $base->getQuery();
            }
        } elseif ($path[0] !== '/') {
            $dir = $base->getPath();
            $dir = $dir === '' ? '/' : substr($dir, 0, (int) strrpos($dir, '/') + 1);
            $path = $dir . $path;
        }

        return $base->withPath(self::removeDots($path))->withQuery($query)->withFragment($ref->getFragment());
    }

    private static function removeDots(string $path): string
    {
        if (!str_contains($path, '.')) {
            return $path;
        }
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($out);
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }
        $result = implode('/', $out);
        if (str_starts_with($path, '/') && !str_starts_with($result, '/')) {
            $result = '/' . $result;
        }
        if (preg_match('#/\.{1,2}$#', $path)) {
            $result = rtrim($result, '/') . '/';
        }

        return $result;
    }

    private static function origin(UriInterface $uri): string
    {
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);

        return $uri->getScheme() . '://' . $uri->getHost() . ':' . $port;
    }

    /** Whether the request (its body) and the sink allow sending again. */
    private static function repeatable(CastRequest $request, mixed $sink): bool
    {
        if (!$request->rewindBody()) {
            return false;
        }

        return match (true) {
            $sink === null, is_string($sink) => true,
            $sink instanceof StreamInterface => $sink->isSeekable(),
            is_resource($sink) => (bool) (stream_get_meta_data($sink)['seekable'] ?? false),
            default => false,
        };
    }

    private static function sinkPosition(mixed $sink): ?int
    {
        return match (true) {
            $sink instanceof StreamInterface => $sink->tell(),
            is_resource($sink) => ($position = ftell($sink)) === false ? null : $position,
            default => null,
        };
    }

    /** Before another attempt: the body from its start, the sink back to where it was. */
    private static function rewind(CastRequest $request, mixed $sink, ?int $sinkStart): void
    {
        $request->rewindBody();
        if ($sinkStart === null) {
            return;   // a path sink is truncated when the next attempt opens it
        }
        if ($sink instanceof StreamInterface) {
            $sink->seek($sinkStart);
        } elseif (is_resource($sink)) {
            ftruncate($sink, $sinkStart);
            fseek($sink, $sinkStart);
        }
    }

    private function fitsDeadline(int $delayMs, ?int $deadlineAt): bool
    {
        return $deadlineAt === null || hrtime(true) + $delayMs * 1_000_000 < $deadlineAt;
    }

    private function deadlineExceeded(
        CastRequest $request,
        TransferOptions $options,
        ?\Throwable $previous = null,
    ): TimeoutException {
        return new TimeoutException(
            sprintf(
                '%s %s did not complete within the deadline of %s s',
                $request->getMethod(),
                CastUri::parse($request->getUri())->redacted(),
                rtrim(rtrim(number_format((float) $options->timeouts->deadline, 3, '.', ''), '0'), '.'),
            ),
            $request,
            deadline: true,
            previous: $previous,
        );
    }

    private function logRetry(CastRequest $request, int $attempt, string $reason, int $delayMs): void
    {
        $this->log('warning', '{method} {uri} attempt {attempt} failed ({reason}), retrying in {delay} ms', [
            'method' => $request->getMethod(),
            'uri' => CastUri::parse($request->getUri())->redacted(),
            'attempt' => $attempt,
            'reason' => $reason,
            'delay' => $delayMs,
        ]);
    }

    /**
     * Placeholders are filled in here: not every PSR-3 logger interpolates them. The context
     * is passed on as well, for loggers that store it.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) {
            return;
        }
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        unset($context['extra']);
        $this->logger->log($level, strtr($message, $replace), $context);
    }

    private function with(string $property, mixed $value): self
    {
        $new = clone $this;
        $new->{$property} = $value;

        return $new;
    }
}
