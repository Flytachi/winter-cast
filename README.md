# Winter Cast

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-cast.svg)](https://packagist.org/packages/flytachi/winter-cast)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.4-777bb4.svg)](https://www.php.net/)
[![PSR-7 · PSR-17 · PSR-18](https://img.shields.io/badge/PSR-7%20%C2%B7%2017%20%C2%B7%2018-blue.svg)](https://www.php-fig.org/psr/psr-18/)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

The HTTP client of the Winter framework: PSR-7/17/18, built on cURL, and at home in both
PHP-FPM and Swoole.

```php
$api = CastClient::create()
    ->withBaseUrl('https://api.example.com/v1')
    ->withBearerToken($token)
    ->withRetry(RetryPolicy::exponential());

$user = $api->get('users/42')->send()->throw()->json();
```

## Highlights

- **Standard.** Messages are PSR-7, factories PSR-17, the client PSR-18. Hand it to any library
  that takes a `ClientInterface`.
- **Immutable.** A configured client is a value: keep it as a bean and share it between coroutines.
- **Concurrent.** `sendAll()` runs requests side by side, on Swoole coroutines or on Fibers with
  `curl_multi`, and the calling code is the same either way.
- **Streams.** Download straight into a file with `sinkTo()`, upload files without loading them,
  and follow both with `onProgress()`.
- **Careful by default.**
  - Retries are opt-in, idempotent-only, and respect `Retry-After`.
  - Timeouts apply per attempt, with an optional deadline over the whole operation.
  - Credentials are not carried across hosts on redirects, and `https` is never downgraded.
  - Header injection is refused.
  - The size of a response body kept in memory is capped.
- **Testable.** `MockTransport` answers from a queue and records what was sent.

## Installation

```bash
composer require flytachi/winter-cast
```

Requirements: PHP 8.4+, `ext-curl`. `ext-swoole` is optional (see [Swoole](#swoole)).

## Contents

- [Quick start](#quick-start)
- [The client](#the-client)
- [Requests](#requests)
- [Responses](#responses)
- [Errors](#errors)
- [Timeouts](#timeouts)
- [Retries](#retries)
- [Redirects](#redirects)
- [Downloads and uploads](#downloads-and-uploads)
- [Concurrent requests](#concurrent-requests)
- [Middleware](#middleware)
- [Logging](#logging)
- [Winter beans](#winter-beans)
- [Testing your code](#testing-your-code)
- [PSR-18 interop](#psr-18-interop)
- [Swoole](#swoole)
- [Security](#security)
- [Upgrading from 1.x](#upgrading-from-1x)

## Quick start

For a script or a quick check, use the `Cast` facade:

```php
use Flytachi\Winter\Cast\Cast;

$ip = Cast::get('https://api.ipify.org', ['format' => 'json'])->send()->json()['ip'];

$created = Cast::post('https://httpbin.org/post')
    ->withJsonBody(['name' => 'winter'])
    ->send();
```

In an application, configure a `CastClient` once and reuse it:

```php
use Flytachi\Winter\Cast\Client\CastClient;
use Flytachi\Winter\Cast\Client\RetryPolicy;

$github = CastClient::create()
    ->withBaseUrl('https://api.github.com')
    ->withBearerToken($token)
    ->withAccept('application/vnd.github+json')
    ->withUserAgent('my-bot/1.0')
    ->withTimeout(10)
    ->withRetry(RetryPolicy::exponential(3)->withStatuses(502, 503, 504));

$repo   = $github->get('repos/flytachi/winter-cast')->send()->throw()->json();
$issues = $github->get('repos/flytachi/winter-cast/issues', ['state' => 'open'])->send()->json();
```

## The client

`CastClient` is immutable: every `with*()` returns a new client, and copies share one
connection pool.

| Method | Sets |
|---|---|
| `withBaseUrl($url)` | the address relative paths are appended to |
| `withHeader($name, $value)`, `withHeaders([...])`, `withAddedHeader()`, `withoutHeader()` | default headers (`withHeaders()` adds; a header of the same name is replaced) |
| `withBearerToken($token)`, `withBasicAuth($user, $password)`, `withAccept(...$types)` | shortcuts for common headers |
| `withUserAgent($agent, exact: false)` | the application's User-Agent |
| `withTimeout($total, $connect = null)`, `withTimeouts(Timeouts)` | [timeouts](#timeouts) |
| `withRetry(RetryPolicy)` | [retries](#retries) |
| `withRedirects(Redirects)` | [redirects](#redirects) |
| `withConcurrency($limit)` | how many requests `sendAll()` runs at once (16) |
| `withMaxResponseSize($bytes)` | the largest body kept in memory (10 MiB; `null` — no limit) |
| `withThrowOnError()` | `send()` throws on 4xx/5xx |
| `withMiddleware(...$middleware)`, `withoutMiddleware($class)` | [middleware](#middleware) |
| `withCurlOptions([...])` | raw cURL options: proxy, TLS… |
| `withLogger(LoggerInterface)` | [logging](#logging) |
| `withTransport(Transport)` | another transport, e.g. `MockTransport` in tests |

Defaults: connect timeout 5 s, 30 s per attempt, no retries, up to 10 redirects, 10 MiB in memory,
HTTP/2 over TLS when the server offers it.

### The base URL is appended to

A relative path is **appended** to the base URL. This is not RFC 3986 resolution, which drops
the last segment of the base:

| Base | Path | Request |
|---|---|---|
| `https://api.com` | `users/42` | `https://api.com/users/42` |
| `https://api.com/v1` | `users` | `https://api.com/v1/users` |
| `https://api.com/v1/` | `/users` | `https://api.com/v1/users` |
| `https://api.com/v1?key=1` | `users?page=2` | `https://api.com/v1/users?key=1&page=2` |

An absolute URL in a request is used as it is.

### Settings: request over client

Defaults, then the client's settings, then the request's own. A header set on the request wins
over the client's header of the same name, and `withTimeout()` on a request wins over the
client's.

## Requests

`$client->get()`, `post()`, `put()`, `patch()`, `delete()`, `head()`, `options()` and
`request($method, $uri)` create a `CastRequest` **bound** to the client; `->send()` sends it
through that client. Requests are immutable too.

```php
$response = $client->post('orders', ['dry_run' => 1])     // query parameters
    ->withHeader('Idempotency-Key', $key)
    ->withJsonBody(['sku' => 'A-1', 'qty' => 2])
    ->withTimeout(5)
    ->send();
```

**Headers:**
- `withHeader()`, `withHeaders([...])` (adds), `withAddedHeader()`, `withoutHeader()`;
- `replaceHeaders([...])` replaces all of them;
- shortcuts `withBearerToken()`, `withBasicAuth()`, `withAccept()`, `withUserAgent()`.

Names are case-insensitive.

**Query:** `withQuery(['page' => 2])` merges parameters into the query; `withQueryParam('page', 2)` sets one.

**Body:**

| Method | Sends |
|---|---|
| `withJsonBody($data)` | JSON, `Content-Type: application/json` |
| `withFormBody([...])` | `application/x-www-form-urlencoded` |
| `withMultipartBody(Multipart\|array)` | `multipart/form-data` (files are streamed) |
| `withStringBody($body, $contentType = null)` | a string |
| `withFileBody($path, $contentType = null)` | a file, streamed from disk |
| `withBody(StreamInterface)` | any PSR-7 stream |

**This send only** (each overrides the client's setting):
- `withTimeout()`, `withTimeouts()`;
- `withRetry()`, `withoutRetry()`, `withRedirects()`;
- `withMaxResponseSize()`, `withThrowOnError()`, `withCurlOptions()`;
- `sinkTo()`, `onProgress()`.

**Sending:**
- `send()` uses the bound client, or the facade's default one;
- `sendVia(ClientInterface)` uses any PSR-18 client.

## Responses

`CastResponse` is a PSR-7 response with shortcuts:

```php
$response->getStatusCode();     // 200
$response->status();            // HttpCode::OK (winter-base)
$response->isSuccess();         // 2xx — also isRedirect(), isClientError(), isServerError()
$response->header('ETag');      // one line, or null when absent
$response->body();              // the whole body as a string
$response->json();              // array, or null when the body is not a JSON object/array
$response->jsonOrFail();        // array, or CastException
$response->throw();             // HttpException on 4xx/5xx, otherwise the response itself
$response->info();              // TransferInfo: times, attempts, redirects, IP, protocol
```

Compressed responses (gzip, deflate, br) are decoded for you. Their `Content-Encoding` and
`Content-Length` describe the bytes on the wire, not the body you get. They are moved to
`X-Encoded-Content-Encoding` and `X-Encoded-Content-Length`.

`info()` returns `totalTime`, `connectTime`, `startTransferTime`, `effectiveUri`, `redirects`,
`attempts`, `primaryIp`, `downloadedBytes`, `uploadedBytes` and `protocol`.

## Errors

A 4xx or 5xx is a **response**, not an exception. That is the PSR-18 rule. You choose where it
becomes one:

```php
$client->get('report')->send()->throw()->json();   // per call
$client->withThrowOnError();                       // for every send() of this client
```

`sendRequest()` (PSR-18) never throws on a status.

```
CastException                       root — implements Psr\Http\Client\ClientExceptionInterface
├── RequestException                cannot be sent: not http(s), no base URL, too many redirects
├── NetworkException                no response: DNS, connection, TLS, dropped
│   └── TimeoutException            isConnect(), isDeadline()
├── TooLargeException               body over withMaxResponseSize(); getLimit()
├── HttpException                   4xx/5xx from throw() / withThrowOnError(); getResponse()
│   ├── ClientErrorException        4xx
│   └── ServerErrorException        5xx
└── BatchException                  sendAll(): some requests got no response

InvalidArgumentException            a bad argument while building: a header with CR/LF,
                                    an invalid URI or method, unencodable JSON, a reserved
                                    cURL option (also a ClientExceptionInterface)
```

```php
use Flytachi\Winter\Cast\Exception\{ClientErrorException, NetworkException, TimeoutException};

try {
    $data = $client->get('report')->send()->throw()->json();
} catch (ClientErrorException $e) {
    $problem = $e->getResponse()->json();          // the error body
} catch (TimeoutException $e) {
    // $e->isConnect() — the connection was not established in time
} catch (NetworkException $e) {
    // $e->isTransient() — a retry may succeed; $e->getCurlErrno() for diagnostics
}
```

## Timeouts

```php
use Flytachi\Winter\Cast\Client\Timeouts;

$client->withTimeout(10);                          // 10 s per attempt, connect stays 5 s
$client->withTimeout(10, connect: 2);

$client->withTimeouts(Timeouts::of(total: 10)->withDeadline(25));
```

| Setting | Limits | Default |
|---|---|---|
| `connect` | establishing the connection | 5 s |
| `total` | one attempt, start to finish | 30 s |
| `idle` | a transfer that stalls: slower than N bytes/s for that long | off |
| `deadline` | the whole operation, retries and pauses included | off |

For large downloads `Timeouts::forDownload(idle: 60, minBytesPerSecond: 1024)` drops the total
limit and keeps the stall limit: a slow but moving download completes, a hung one fails.

## Retries

Retries are off until you ask for them:

```php
use Flytachi\Winter\Cast\Client\RetryPolicy;

$client->withRetry(RetryPolicy::exponential(attempts: 3, delayMs: 500));   // 500 ms, 1 s (±30%)
$client->withRetry(RetryPolicy::fixed(5, 200)->withStatuses(429, 503));
```

- **Methods:** only idempotent ones (`GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS`, `TRACE`) are
  retried. `withNonIdempotent()` adds `POST`/`PATCH`; `withMethods(...)` sets the list.
- **Failures:** transient network errors (could not connect, timed out, connection reset, empty
  reply) are retried. Statuses are retried only when listed with `withStatuses()`.
- **Pauses:** `Retry-After` (seconds or a date) is respected, capped by `withMaxDelay()` (30 s).
  A pause never blocks other requests of `sendAll()`, nor other coroutines.
- **What repeats:** middleware runs once, and the attempts happen inside it. The body is rewound
  and a `sinkTo()` target is reset before each attempt. A body or sink that cannot be rewound is
  not retried.
- **Counting:** `$response->info()->attempts` has the count.

## Redirects

Redirects are followed by default, up to 10:
- `303` becomes `GET`, and so do `301`/`302` after a `POST`; `307`/`308` keep the method and
  body;
- `Authorization`, `Cookie` and `Proxy-Authorization` are dropped when the host changes;
- `https` → `http` is not followed unless `Redirects::follow(allowDowngrade: true)`.

`Redirects::none()` returns the 3xx as it is.

## Downloads and uploads

```php
use Flytachi\Winter\Cast\Client\Timeouts;
use Flytachi\Winter\Cast\Message\TransferProgress;

$response = $client->get('backups/latest')
    ->sinkTo('/var/backups/db.sql.gz')
    ->withTimeouts(Timeouts::forDownload())
    ->onProgress(fn(TransferProgress $p) => printf("\r%.1f%%", $p->downloadPercent() ?? 0))
    ->send()
    ->throw();
```

- **Only 2xx is written to the target.** An error response stays in memory, so you can read
  `->json()`, and the file is not touched.
- **The size limit** does not apply to a sink.
- **Targets:**
  - a path is created or truncated, and removed if the transfer fails;
  - an open resource or a PSR-7 stream is written from its current position. That is how
    resuming works: open the file for appending and send a `Range` header.
- **Uploads:** `withFileBody($path)`, `withBody(CastStream::fromFile($path))` and file parts
  of `withMultipartBody()` are streamed from disk.

```php
use Flytachi\Winter\Cast\Message\Multipart;

$client->post('avatars')->withMultipartBody(
    Multipart::create()
        ->withField('user', '42')
        ->withFile('image', '/tmp/me.png', 'me.png', 'image/png'),
)->send();

// or as an array: ['user' => '42', 'image' => new CURLFile('/tmp/me.png')]
```

Parts that share a name (`files[]` twice) are sent too. cURL cannot take them as fields, so such
a body is rendered and sent as a stream.

## Concurrent requests

```php
$responses = $client->sendAll([
    'user'   => $client->get('users/42'),
    'orders' => $client->get('orders', ['user' => 42]),
    'stats'  => $client->get('stats/42'),
]);

$responses['orders']->json();
```

- **Results:** responses come back with the requests' keys, in their order. Every request passes
  the whole chain: middleware, retries, redirects, sink.
- **Statuses:** a 4xx/5xx is a response in the result. If some requests got **no** response,
  `BatchException` is thrown after all have finished, with `responses()` and `failures()`.
- **Generators:** `$requests` may be a generator; it is read as sending goes on.
- **Concurrency:** at most `withConcurrency()` requests (16) run at once, or what you pass as
  the second argument.
- **How it runs:** inside a Swoole coroutine each request gets a coroutine. Elsewhere (FPM,
  CLI) each runs in a Fiber over one shared `curl_multi`, so the total time is that of the
  slowest request.

## Middleware

A middleware sees every request of the client and its response:

```php
use Flytachi\Winter\Cast\Message\{CastRequest, CastResponse};
use Flytachi\Winter\Cast\Middleware\CastMiddleware;

final class TimingMiddleware implements CastMiddleware
{
    public function __construct(private Metrics $metrics) {}

    public function process(CastRequest $request, callable $next): CastResponse
    {
        $response = $next($request);
        $this->metrics->observe($request->getUri()->getHost(), $response->info()->totalTime);

        return $response;
    }
}

$client = $client->withMiddleware(new TimingMiddleware($metrics));

// a closure works too
$client = $client->withMiddleware(
    fn(CastRequest $request, callable $next) => $next($request->withHeader('X-Request-ID', $id)),
);
```

The first one added runs first (outermost). Middleware runs once per send; retries happen inside
it, closer to the network.

**Built-in:**

| Middleware | Does |
|---|---|
| `AuthMiddleware::bearer(string\|callable $token)` | `Authorization: Bearer …`; a function is asked on every request |
| `AuthMiddleware::basic($user, $password)` | `Authorization: Basic …` |
| `AuthMiddleware::header($name, string\|callable $value)` | a key in a header of its own |
| `new HeadersMiddleware(array\|callable $headers)` | headers computed per request (`X-Request-ID`) |
| `new RefreshTokenMiddleware(callable $refresh, $maxRetries = 1)` | on 401, gets a new token and sends the request again |

```php
$client = $client
    ->withMiddleware(new RefreshTokenMiddleware(fn() => $tokens->refresh()))
    ->withMiddleware(AuthMiddleware::bearer(fn() => $tokens->current()));
```

Constant headers need no middleware: use the client's `withHeaders()`.

## Logging

```php
$client = $client->withLogger($logger);   // any PSR-3 logger
```

- **`debug`:** one line per request, e.g. `GET https://api.example.com/v1/users → 200 (84 ms, 2 attempts)`.
- **`warning`:** one line per retry.
- **`error`:** a failure after the last attempt.

The logger never sees headers, bodies or query strings. Placeholders are filled in by the client,
so loggers that do not interpolate print complete lines.

## Winter beans

```php
use Flytachi\Winter\Cast\Client\CastClient;
use Flytachi\Winter\Kernel\App\Attribute\{Bean, Configuration, Value};
use Flytachi\Winter\Logger\LoggerFactory;

#[Configuration]
final class HttpConfiguration
{
    #[Bean(name: 'github')]
    public function github(#[Value('GITHUB_TOKEN')] string $token): CastClient
    {
        return CastClient::create()
            ->withBaseUrl('https://api.github.com')
            ->withBearerToken($token)
            ->withLogger(LoggerFactory::getLogger('github'));
    }
}
```

An API client of your own needs no base class. Wrap a `CastClient`:

```php
final class WeatherApi
{
    public function __construct(private CastClient $http) {}

    public static function create(string $key): self
    {
        return new self(CastClient::create()
            ->withBaseUrl('https://api.weather.example/v2')
            ->withHeader('X-Api-Key', $key));
    }

    public function today(string $city): array
    {
        return $this->http->get('today', ['city' => $city])->send()->throw()->jsonOrFail();
    }
}
```

## Testing your code

```php
use Flytachi\Winter\Cast\Message\{CastRequest, CastResponse};
use Flytachi\Winter\Cast\Transport\MockTransport;

$mock = new MockTransport(
    CastResponse::fromJson(['id' => 1], 201),
    fn(CastRequest $request) => CastResponse::fromString('', 204),
    MockTransport::fail(),                       // a dropped connection
);
$client = CastClient::create()->withBaseUrl('https://api.test')->withTransport($mock);

(new OrderService($client))->create(['sku' => 'A-1']);

$this->assertSame('POST', $mock->lastRequest()->getMethod());
$this->assertSame('{"sku":"A-1"}', (string) $mock->lastRequest()->getBody());
```

Everything except the network stays real: the base URL, headers, middleware, retries, redirects
and `throwOnError`.

To check what was sent and how much of the queue was used, read `requests()` and `remaining()`.

## PSR-18 interop

```php
use Flytachi\Winter\Cast\Factory\CastFactory;

$factory = new CastFactory();     // all four PSR-17 factories
$sdk = new SomeVendorSdk(
    httpClient: $client,          // Psr\Http\Client\ClientInterface
    requestFactory: $factory,
    streamFactory: $factory,
);
```

The vendor's requests go through your middleware, logging, retries and User-Agent. Foreign PSR-7
requests are accepted everywhere.

## Swoole

Nothing to configure. With `Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`, which winter-kernel
enables, and Swoole built with `curl-native`:
- cURL calls yield instead of blocking;
- `sendAll()` uses coroutines;
- retry pauses yield to other coroutines.

One client can be shared by any number of coroutines: it is immutable, and each transfer takes
a connection from the pool for itself.

## Security

- **TLS:** verification is always on. Disabling it takes an explicit `withCurlOptions()` and is
  meant for development only.
- **Header injection:** CR, LF or NUL in a header name or value is refused.
- **Redirects:** credentials stay on their origin, and `https` is never downgraded.
- **Protocols:** only `http` and `https` can be sent.
- **Reserved cURL options:** options the library relies on (URL, method, headers, body,
  callbacks, redirects) cannot be overridden with `withCurlOptions()`; the error names the
  method to use instead.
- **Logging:** the logger never sees headers, bodies or query strings.
- **`var_dump()`:** dumps of clients and requests mask `Authorization`, `Proxy-Authorization`,
  `Cookie` and `X-Api-Key`.

## Upgrading from 1.x

2.0 is a new API and is not compatible with 1.x. See [CHANGELOG.md](CHANGELOG.md#upgrading-from-1x) for the
mapping from old calls to new ones.

## License

MIT — see [LICENSE](LICENSE).
