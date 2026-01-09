# Winter Cast Component

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-cast.svg)](https://packagist.org/packages/flytachi/winter-cast)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

A modern, fluent HTTP client for PHP 8.3+ with a focus on developer experience, type safety, and ease of use.

## Features

✨ **Fluent API** - Chainable methods for building requests  
🎯 **Type-Safe** - Full PHP 8.3+ type declarations  
🚀 **Zero Config** - Works out of the box with sensible defaults  
🔄 **Auto-Retry** - Built-in retry logic for failed requests  
⚡ **Fast** - Powered by cURL for maximum performance  
🛡️ **Secure** - URL validation, response size limits, timeout controls  
🎭 **Facade Pattern** - Simple static interface or DI-friendly client  
📝 **PSR-3 Logging** - Automatic request/response logging  
💥 **Smart Exceptions** - Specific exception types for different errors  

---

## Installation

```bash
composer require flytachi/winter-cast
```

**Requirements:**
- PHP >= 8.3
- ext-curl

---

## Quick Start

### Simple GET Request

```php
use Flytachi\Winter\Cast\Cast;

// One-liner
$response = Cast::sendGet('https://api.example.com/users');
$users = $response->json();

// Or with fluent API
$response = Cast::get('https://api.example.com/users')
    ->timeout(5)
    ->send();
```

### POST with JSON

```php
use Flytachi\Winter\Cast\Cast;
use Flytachi\Winter\Cast\Common\CastHeader;

$response = Cast::post('https://api.example.com/users')
    ->withHeaders(
        CastHeader::instance()
            ->json()
            ->authBearer($token)
    )
    ->withJsonBody([
        'name' => 'John Doe',
        'email' => 'john@example.com'
    ])
    ->throwOnError()
    ->send();

$user = $response->json();
```

---

## Core Concepts

### 1. Cast Facade (Simple Usage)

The `Cast` facade provides a zero-configuration entry point:

```php
// Build request (returns CastRequest)
$request = Cast::get('https://api.com/data');

// Send immediately (returns CastResponse)
$response = Cast::sendGet('https://api.com/data');
```

**Available methods:**
- `get()`, `post()`, `put()`, `patch()`, `delete()`, `head()`
- `sendGet()`, `sendPost()`, `sendPut()`, `sendPatch()`, `sendDelete()`, `sendHead()`

### 2. CastRequest (Request Builder)

Build requests with a fluent interface:

```php
use Flytachi\Winter\Cast\Common\CastRequest;

$request = CastRequest::get('https://api.com/users')
    ->withQueryParam('page', 1)
    ->withQueryParam('limit', 10)
    ->timeout(30)
    ->connectTimeout(5)
    ->retry(3, 1000)  // 3 retries, 1s delay
    ->maxResponseSize(50 * 1024 * 1024)  // 50MB limit
    ->throwOnError()
    ->send();
```

### 3. CastHeader (Header Builder)

Fluent interface for headers:

```php
use Flytachi\Winter\Cast\Common\CastHeader;

$headers = CastHeader::instance()
    ->json()                      // Accept + Content-Type
    ->authBearer($token)          // Authorization: Bearer
    ->acceptLanguage('ru')        // Accept-Language
    ->userAgent('MyApp/1.0')      // User-Agent
    ->set('X-Custom', 'value');   // Custom header
```

**Built-in helpers:**
- `json()` - Set Accept and Content-Type for JSON
- `authBearer($token)` - Bearer authentication
- `authBasic($user, $pass)` - Basic authentication
- `acceptLanguage($lang)` - Accept-Language header
- `userAgent($agent)` - User-Agent header
- `referer($url)` - Referer header
- `contentType($type)` - Content-Type header

### 4. CastResponse (Response Object)

Immutable response with helper methods:

```php
$response->statusCode;        // int: HTTP status code
$response->body();            // ?string: Raw body
$response->json();            // ?array: Parsed JSON
$response->headers();         // array: All headers
$response->header('X-Rate-Limit');  // ?string: Specific header
$response->info();            // array: cURL info

// Status checks
$response->isSuccess();       // 2xx
$response->isClientError();   // 4xx
$response->isServerError();   // 5xx
$response->isRedirection();   // 3xx
$response->isConnectionError(); // Connection failed
```

---

## Advanced Usage

### Query Parameters

```php
// Static params
Cast::get('https://api.com/search', ['q' => 'php', 'type' => 'repos']);

// Dynamic params
Cast::get('https://api.com/search')
    ->withQueryParam('q', 'php')
    ->withQueryParam('type', 'repos')
    ->withQueryParams(['sort' => 'stars', 'order' => 'desc'])
    ->send();
```

### Request Body

```php
// JSON body
Cast::post($url)
    ->withJsonBody(['key' => 'value'])
    ->send();

// Form data
Cast::post($url)
    ->withFormParams(['key' => 'value'])
    ->send();

// Multipart (file upload)
Cast::post($url)
    ->withMultipartBody([
        'file' => new CURLFile('/path/to/file.jpg'),
        'title' => 'My Image'
    ])
    ->send();

// Raw body
Cast::post($url)
    ->withBody('<xml>data</xml>')
    ->send();
```

### Timeouts & Retries

```php
Cast::get($url)
    ->timeout(30)           // Total request timeout (seconds)
    ->connectTimeout(10)    // Connection timeout (seconds)
    ->retry(3, 2000)        // 3 retries, 2 second delay
    ->send();
```

### Error Handling

```php
use Flytachi\Winter\Cast\Exception\TimeoutException;
use Flytachi\Winter\Cast\Exception\ConnectionException;
use Flytachi\Winter\Cast\Exception\RequestException;

try {
    $response = Cast::get($url)
        ->throwOnError()  // Enable exceptions for HTTP errors
        ->send();
        
} catch (TimeoutException $e) {
    // Request timed out - retry with longer timeout
    echo "Timeout: {$e->getMessage()}";
    
} catch (ConnectionException $e) {
    // Connection failed - try backup server
    echo "Connection failed: {$e->getMessage()}";
    
} catch (RequestException $e) {
    // HTTP error (4xx/5xx) - access response object
    echo "HTTP {$e->response->statusCode}: {$e->response->body()}";
}
```

**Exception hierarchy:**
- `CastException` - Base exception
  - `TimeoutException` - Request/connection timeout
  - `ConnectionException` - DNS, connection refused, etc.
  - `RequestException` - HTTP errors (4xx/5xx)

### Dependency Injection

```php
use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastRequest;

class ApiService
{
    public function __construct(
        private readonly CastClient $httpClient
    ) {}
    
    public function fetchUsers(): array
    {
        $request = CastRequest::get('https://api.com/users')
            ->timeout(5);
            
        $response = $this->httpClient->send($request);
        return $response->json();
    }
}

// DI Container configuration
$client = new CastClient(
    defaultTimeout: 30,
    defaultConnectTimeout: 10
);
$service = new ApiService($client);
```

### Custom Client Configuration

```php
$client = new CastClient(
    defaultTimeout: 60,
    defaultConnectTimeout: 15,
    defaultOptions: [
        CURLOPT_SSL_VERIFYPEER => false,  // Disable SSL verification (dev only!)
        CURLOPT_FOLLOWLOCATION => true,
    ]
);

// Use custom client
Cast::setGlobalClient($client);
```

---

## Security Features

### URL Validation

Automatically validates URLs and rejects dangerous protocols:

```php
// ✅ Allowed
Cast::get('https://api.com/data')->send();
Cast::get('http://localhost/api')->send();

// ❌ Rejected (throws CastException)
Cast::get('file:///etc/passwd')->send();
Cast::get('ftp://example.com')->send();
```

### Response Size Limit

Prevent memory exhaustion with automatic size limits:

```php
// Default: 10MB limit
Cast::get($url)->send();

// Custom limit
Cast::get($url)
    ->maxResponseSize(50 * 1024 * 1024)  // 50MB
    ->send();

// Disable limit (use with caution!)
Cast::get($url)
    ->maxResponseSize(null)
    ->send();
```

---

## Logging

Automatic PSR-3 logging via `LoggerRegistry`:

**Logged events:**
- Request start (DEBUG)
- Request completion (DEBUG)
- Request failures (DEBUG)
- Retry attempts (DEBUG)
- HTTP errors (DEBUG)

**Example log output:**
```
[DEBUG] Sending request {"method":"GET","url":"https://api.com/users","timeout":10}
[DEBUG] Completed request {"method":"GET","url":"https://api.com/users","status":200,"duration":0.234}
```

---

## Testing

```php
use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastResponse;

// Mock client for testing
class MockClient extends CastClient
{
    public function send(CastRequest $request): CastResponse
    {
        return new CastResponse(
            statusCode: 200,
            body: '{"id":1,"name":"Test User"}',
            headers: ['Content-Type' => 'application/json'],
            info: []
        );
    }
}

$service = new ApiService(new MockClient());
```

---

## Examples

### GitHub API

```php
$repos = Cast::get('https://api.github.com/users/flytachi/repos')
    ->withHeaders(
        CastHeader::instance()
            ->acceptLanguage('en')
            ->userAgent('Winter-Cast/1.0')
    )
    ->send()
    ->json();
```

### Authenticated API Request

```php
$response = Cast::post('https://api.example.com/data')
    ->withHeaders(
        CastHeader::instance()
            ->json()
            ->authBearer($accessToken)
    )
    ->withJsonBody(['key' => 'value'])
    ->throwOnError()
    ->send();
```

### File Download

```php
$response = Cast::get('https://example.com/file.pdf')
    ->maxResponseSize(100 * 1024 * 1024)  // 100MB
    ->timeout(120)
    ->send();

file_put_contents('/tmp/file.pdf', $response->body());
```

### Pagination

```php
$page = 1;
$allUsers = [];

do {
    $response = Cast::get('https://api.com/users')
        ->withQueryParam('page', $page)
        ->withQueryParam('limit', 100)
        ->send();
        
    $data = $response->json();
    $allUsers = array_merge($allUsers, $data['users']);
    $page++;
    
} while (!empty($data['users']));
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

---

## Credits

- **Author:** Flytachi
- **Framework:** Winter Framework
- **Built with:** PHP 8.3+, cURL
