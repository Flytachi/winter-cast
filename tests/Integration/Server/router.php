<?php

/**
 * The echo server of the integration tests: `php -S 127.0.0.1:{port} router.php`.
 *
 * Routes:
 *   /echo                       the request as JSON: method, uri, headers, body
 *   /status/{code}              that status, a JSON body
 *   /redirect/{code}?to={url}   a redirect with that status
 *   /bytes/{n}                  n bytes ('x'), with Content-Length
 *   /stream/{n}                 n bytes in chunks, without Content-Length
 *   /slow/{ms}                  waits, then 200
 *   /stall                      a few bytes, then a long pause
 *   /flaky/{key}/{failures}     503 (Retry-After: 0) the first {failures} times, then 200
 *   /gzip                       a gzip-encoded text body
 *   /cookie                     sets a cookie
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = array_values(array_filter(explode('/', $path), 'strlen'));
$route = $parts[0] ?? '';

$json = static function (mixed $data, int $status = 200, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
};

switch ($route) {
    case 'echo':
        $headers = [];
        foreach (getallheaders() as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        $body = file_get_contents('php://input');
        $json([
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri' => $_SERVER['REQUEST_URI'],
            'query' => $_GET,
            'headers' => $headers,
            'body' => $body,
            'bodyLength' => strlen($body),
            'post' => $_POST,
            'files' => array_map(
                static fn(array $f) => ['name' => $f['name'], 'type' => $f['type'], 'size' => $f['size'],
                    'sha1' => sha1_file($f['tmp_name'])],
                $_FILES,
            ),
        ]);

    case 'status':
        $json(['status' => (int) ($parts[1] ?? 200)], (int) ($parts[1] ?? 200));

    case 'redirect':
        http_response_code((int) ($parts[1] ?? 302));
        header('Location: ' . ($_GET['to'] ?? '/echo'));
        exit;

    case 'bytes':
        $n = (int) ($parts[1] ?? 0);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $n);
        echo str_repeat('x', $n);
        exit;

    case 'stream':
        $n = (int) ($parts[1] ?? 0);
        header('Content-Type: application/octet-stream');
        while ($n > 0) {
            $chunk = min($n, 8192);
            echo str_repeat('x', $chunk);
            flush();
            $n -= $chunk;
        }
        exit;

    case 'slow':
        usleep((int) ($parts[1] ?? 100) * 1000);
        $json(['slept' => (int) ($parts[1] ?? 100)]);

    case 'stall':
        header('Content-Type: application/octet-stream');
        header('Content-Length: 1000000');
        echo str_repeat('x', 100);
        flush();
        sleep(10);
        exit;

    case 'flaky':
        $file = sys_get_temp_dir() . '/cast-flaky-' . preg_replace('/\W/', '', $parts[1] ?? 'x');
        $count = (int) @file_get_contents($file);
        file_put_contents($file, (string) ($count + 1));
        if ($count < (int) ($parts[2] ?? 1)) {
            $json(['attempt' => $count + 1], 503, ['Retry-After' => '0']);
        }
        @unlink($file);
        $json(['attempt' => $count + 1]);

    case 'gzip':
        $body = str_repeat('compressible ', 1000);
        $gz = gzencode($body);
        header('Content-Type: text/plain');
        header('Content-Encoding: gzip');
        header('Content-Length: ' . strlen($gz));
        echo $gz;
        exit;

    case 'cookie':
        setcookie('session', 'abc');
        $json(['ok' => true]);

    default:
        $json(['error' => 'not found'], 404);
}
