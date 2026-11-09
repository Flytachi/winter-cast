# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-09

A new major version, built on PSR-7, PSR-17 and PSR-18. The API is designed anew and is **not**
compatible with 1.x; see [Upgrading from 1.x](#upgrading-from-1x) below.

### Added

- **PSR-18 client.** `CastClient` implements `ClientInterface`.
  - `sendRequest()` follows the PSR-18 rule: a 4xx/5xx is a response, never an exception.
  - `send()` returns a `CastResponse` and throws on errors only with `withThrowOnError()`.
- **PSR-7 messages.** `CastRequest`, `CastResponse`, `CastStream` and `CastUri` pass the
  `php-http/psr7-integration-tests` suite.
- **PSR-17 factories.** `CastFactory` implements all four.
- **Immutable, configurable client.**
  - Settings: `withBaseUrl()`, default headers (`withHeader()`, `withHeaders()`,
    `withBearerToken()`, `withBasicAuth()`, `withAccept()`, `withUserAgent()`), `withTimeout()` /
    `withTimeouts()`, `withRetry()`, `withRedirects()`, `withConcurrency()`,
    `withMaxResponseSize()`, `withThrowOnError()`, `withMiddleware()` / `withoutMiddleware()`,
    `withCurlOptions()`, `withLogger()`, `withTransport()`.
  - Getters: `getBaseUrl()`, `getHeaders()`, `getTimeouts()`, `getRetryPolicy()`,
    `getRedirects()`, `getMiddleware()`.
  - Request builders bound to the client: `get()`, `post()`, `put()`, `patch()`, `delete()`,
    `head()`, `options()`, `request()`.
- **Base URL joining.** A relative path is appended to the base URL:
  `https://api.com/v1` + `users` → `https://api.com/v1/users`.
- **Request settings per send.** Each overrides the client's setting:
  - `withTimeout()`, `withTimeouts()`, `withRetry()`, `withoutRetry()`, `withRedirects()`;
  - `withMaxResponseSize()`, `withThrowOnError()`, `withCurlOptions()`.
- **Request bodies:** `withJsonBody()`, `withFormBody()`, `withMultipartBody()` (`Multipart`
  builder or array), `withStringBody()`, `withFileBody()`, and PSR-7 `withBody()`.
  - Files and large streams are sent from disk through `CURLOPT_READFUNCTION`.
- **Downloads.** `sinkTo(path|resource|stream)` streams a 2xx body into a file or stream.
  - An error response stays in memory, and the target is left untouched.
  - A partly written file is removed when the transfer fails.
  - Writing from the stream's current position enables resuming.
- **Decoded responses.** gzip/deflate/br responses are decoded. Their `Content-Encoding` and
  `Content-Length` move to `X-Encoded-Content-Encoding` / `X-Encoded-Content-Length`.
- **Progress.** `onProgress(fn(TransferProgress $p) => …)` for downloads and uploads.
- **`Timeouts`.**
  - `of()` sets `connect` and `total` (per attempt).
  - `forDownload()` drops the total limit and keeps a stall (`idle`) limit.
  - `withDeadline()` bounds the whole operation, retries and pauses included.
- **`RetryPolicy`.**
  - Constructors: `none()`, `exponential()` (jittered), `fixed()`.
  - Methods: idempotent only by default; `withNonIdempotent()`, `withMethods()`.
  - Triggers: transient network errors; statuses only via `withStatuses()`.
  - Pauses: `Retry-After` is honoured, capped by `withMaxDelay()`.
- **`Redirects`.** Redirects are handled by the client:
  - methods are rewritten as in RFC 9110 (303 → GET; 301/302 after POST → GET; 307/308 keep
    method and body);
  - `Authorization`, `Cookie` and `Proxy-Authorization` are dropped on a cross-origin hop;
  - `https` → `http` is not followed unless allowed;
  - `Location` is resolved per RFC 3986.
- **Concurrency.** `sendAll(iterable $requests, ?int $concurrency)` runs requests at once and
  returns the responses by the requests' keys, in their order.
  - It runs on Swoole coroutines inside a coroutine, otherwise on Fibers over a shared
    `curl_multi`.
  - It accepts generators.
  - If some requests got no response, `BatchException` is thrown after all have finished.
- **Middleware.** `CastMiddleware::process(CastRequest $request, callable $next)`; closures are
  accepted too.
  - Built-in: `AuthMiddleware::bearer()` / `basic()` / `header()` (a token may be a function),
    `HeadersMiddleware` (static or computed per request), `RefreshTokenMiddleware`
    (on 401 gets a new token and resends).
- **Logging.** `withLogger()`:
  - `debug`: a line per request — method, URL without query, status, time, attempts, redirects;
  - `warning`: one per retry;
  - `error`: a failure after the last attempt.

  Headers and bodies are never logged. Placeholders are interpolated by the client.
- **Testing.** `MockTransport` answers from a queue of responses, functions or exceptions.
  - `fail()` adds an entry that fails like a dropped connection.
  - `requests()`, `lastRequest()` and `remaining()` show what was sent and what is left.
  - `CastResponse::fromJson()` and `fromString()` build responses for tests.
- **Response shortcuts:**
  - status: `status()` (winter-base `HttpCode`), `isSuccess()`, `isRedirect()`,
    `isClientError()`, `isServerError()`;
  - body: `body()`, `json()`, `jsonOrFail()`;
  - other: `header()`, `throw()`, `isSunk()`, `info()`.
- **`TransferInfo`:** times, attempts, redirects, effective URI, IP, bytes, protocol.
- **Exceptions.**
  - `NetworkException`: `isTransient()`, `getCurlErrno()`.
  - `TimeoutException`: `isConnect()`, `isDeadline()`.
  - `TooLargeException`: `getLimit()`.
  - `HttpException` with `ClientErrorException` (4xx) and `ServerErrorException` (5xx).
  - `BatchException`.
  - `InvalidArgumentException` for bad arguments while building a request.
  - All implement `ClientExceptionInterface`.
- **Connections.** A pool of cURL handles keeps connections open. DNS and TLS sessions are shared
  between handles. HTTP/2 over TLS is used when cURL and the server support it.
- **Facade.** The `Cast` facade has explicit static methods: `get()` … `options()`, `send()`,
  `sendAll()`, `client()`, `setClient()`.

### Changed

- **Requirements:**
  - PHP **8.4** or newer;
  - new dependencies: `psr/http-client`, `psr/http-factory`, `psr/http-message` ^2.0;
  - the package `provide`s the PSR-18, PSR-17 and PSR-7 implementations.
- **Namespaces:** `Common\*` became `Client\*` and `Message\*`; `Stereotype\*` became
  `Middleware\*`.
- **Settings precedence:** a request's settings always win over the client's.
- **HEAD requests** are sent with `CURLOPT_NOBODY` and no longer wait for a body.
- **Response headers** are those of the final response only, not mixed with redirect hops or
  `100 Continue`.
- **Retries:**
  - only idempotent methods by default;
  - retries happen after middleware, so middleware runs once per send.
- **Header errors:** a header name or value with CR, LF or NUL is refused with
  `InvalidArgumentException`.
- **Reserved cURL options:** options the library relies on are refused by `withCurlOptions()`,
  with a hint to the method to use instead.

### Removed

- `CastHeader`: use the header methods of the request or client.
- `ApiService`: wrap a `CastClient` in a class of your own.
- `LoggingMiddleware`: use `withLogger()`.
- `ConnectionException`: use `NetworkException`.
- The magic `Cast::sendGet()` and other `__callStatic` methods.
- `Cast::setGlobalClient()` / `getGlobalClient()`: use `setClient()` / `client()`.

### Upgrading from 1.x

| 1.x | 2.0 |
|---|---|
| `Common\CastClient` | `Client\CastClient` |
| `Common\CastRequest`, `Common\CastResponse` | `Message\CastRequest`, `Message\CastResponse` |
| `new CastClient(10, 5, [opts])` | `CastClient::create()->withTimeout(10, 5)->withCurlOptions([…])` |
| `$client->addMiddleware($m)` | `$client = $client->withMiddleware($m)` (immutable) |
| `CastMiddleware::handle($request, $next)` | `CastMiddleware::process($request, $next)` |
| `CastHeader::instance()->json()->authBearer($t)` | `->withAccept('application/json')->withBearerToken($t)` or `->withHeaders([...])` |
| `BearerAuthMiddleware($t)` / `BasicAuthMiddleware($u, $p)` | `AuthMiddleware::bearer($t)` / `AuthMiddleware::basic($u, $p)` |
| `RetryOnUnauthorizedMiddleware` | `RefreshTokenMiddleware` |
| `LoggingMiddleware($logger)` | `$client->withLogger($logger)` |
| `Stereotype\ApiService` | a class of your own around `CastClient` |
| `Cast::sendGet($url)` | `Cast::get($url)->send()` |
| `Cast::setGlobalClient()` / `getGlobalClient()` | `Cast::setClient()` / `Cast::client()` |
| `->timeout(int)` | `->withTimeout(float)` |
| `->retry(3, 500)` | `->withRetry(RetryPolicy::exponential(3, 500))` |
| retries of any method | idempotent only; `POST` with `->withNonIdempotent()` |
| `->withBody(string)` | `->withStringBody(string)` (`withBody()` takes a PSR-7 stream) |
| `->withFormParams()` | `->withFormBody()` |
| `->throwOnError()` | `->withThrowOnError()` or `->send()->throw()` |
| `$response->statusCode`, `$response->body` | `getStatusCode()`, `body()` |
| `$response->headers` (`'Name' => 'v'\|[…]`) | `getHeaders()` (`'Name' => ['v', …]`, PSR-7) |
| `RequestException` on 4xx/5xx | `HttpException` / `ClientErrorException` / `ServerErrorException` |
| `ConnectionException` | `NetworkException` |

## [1.4.0] - 2026-11-08

Headers can now be built up step by step instead of being replaced as a whole, and every
request identifies itself with a User-Agent.

### Added

- `CastRequest::withHeader($name, $value)` — sets one header, keeps the others.
- `CastRequest::withoutHeader($name)` — removes one header, keeps the others.
- `CastRequest::replaceHeaders($headers)` — replaces every header of the request (what
  `withHeaders()` used to do).
- `CastRequest::withHeaders()` and `replaceHeaders()` accept a `name => value` array as well as
  a `CastHeader`.
- `CastClient` constructor parameter `defaultHeaders` (a `CastHeader` or an array) — headers sent
  with every request. A request's own headers, and those set by middleware, win over them name
  by name. `CastClient::getDefaultHeaders()` returns a copy.
- Default User-Agent: every request is sent with `WinterCast/{version} curl/{version}`. A
  User-Agent of your own comes first, the library's tokens follow:
  `MyApp/1.0 WinterCast/1.4.0 curl/8.11.0` (RFC 9110 §10.1.5 — most significant first). The suffix
  is added at send time, also to a User-Agent from the client defaults or from middleware, and
  never twice.
- `CastHeader::userAgent($agent, exact: true)` — sends the User-Agent exactly as given, without
  the suffix; `CastHeader::isUserAgentExact()`.
- `CastHeader::from(array)`, `has()`, `get()`, `all()` and `merge(CastHeader|array)`.
- Integration tests (`tests/Integration`) that check the headers actually sent, against a local
  `php -S`.
- `composer-runtime-api: ^2.0` requirement — the version in the User-Agent comes from
  Composer's `InstalledVersions`.

### Changed

- **`CastRequest::withHeaders()` now merges** the given headers into the request's headers: a
  header of the same name is replaced, the others are kept. Before, it replaced every header of
  the request. Use `replaceHeaders()` where the old behaviour is wanted.
- **Header names are case-insensitive** in `CastHeader`: `set('content-type', …)` replaces a
  `Content-Type` set before instead of adding a second header; `remove()`, `has()` and `get()`
  match any case. The spelling sent is the one set last.
- A request keeps its own copy of the headers: changing a `CastHeader` after passing it to
  `withHeaders()`, or the one returned by `getHeaders()`, no longer changes the request.
  `getHeaders()` returns a copy.
- Requests without a User-Agent are now sent with `WinterCast/{version} curl/{version}`; a
  User-Agent set with `userAgent()` or `set('User-Agent', …)` gets that suffix appended unless
  `exact: true` is used.

### Fixed

- `BearerAuthMiddleware`, `BasicAuthMiddleware` and `RetryOnUnauthorizedMiddleware` dropped every
  other header of the request (a JSON request went out without `Content-Type` and `Accept`, and
  with cURL's defaults instead); they now set only `Authorization`.
- `HeadersMiddleware` replaced the request's headers with its own; it now adds on top of them.
  It also no longer hands the same `CastHeader` instance to every request.
- `CastResponse::json()` threw a `TypeError` on a body that is valid JSON but not an object or
  an array (`123`, `"text"`, `true`, `null`); it now returns `null`, as its `?array` return type
  promises.
- `withMultipartBody()` now removes a `Content-Type` set in any letter case.

### Security

- `CastHeader::set()` rejects a header name that is empty or contains `:`, whitespace, a line
  break or NUL, and a value that contains a line break or NUL (`CastException`). A value taken
  from user input can no longer inject headers of its own into a request.

### Upgrading from 1.3

Most code needs no change. Check these three cases:

1. You call `withHeaders()` more than once on a request **to drop** the headers set before —
   switch that call to `replaceHeaders()`.
2. You rely on the server seeing the bare cURL default User-Agent (or none) — pass your agent
   with `userAgent($agent, exact: true)`.
3. You set two headers whose names differ only in case and expect both to be sent — HTTP treats
   them as one header; set it once.

[2.0.0]: https://github.com/flytachi/winter-cast/compare/v1.4.0...v2.0.0
[1.4.0]: https://github.com/flytachi/winter-cast/compare/v1.3.1...v1.4.0
