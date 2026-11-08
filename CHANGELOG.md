# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.4.0]: https://github.com/flytachi/winter-cast/compare/v1.3.1...v1.4.0
