<?php

declare(strict_types=1);

/**
 * Minimal HTTP client for the regression suite (streams only, no extensions needed).
 * Keeps the session cookie and CSRF token between calls, like the SPA does.
 */
class HttpClient
{
    public string $cookie = '';
    public string $csrfToken = '';

    public function __construct(public string $baseUrl)
    {
    }

    /**
     * @param array<string,string> $extraHeaders
     * @return array{status:int,json:?array,raw:string,headers:array<string,string>}
     */
    public function request(
        string $method,
        string $route,
        mixed $body = null,
        array $extraHeaders = [],
        bool $includeXpt = true,
        bool $includeCsrf = true
    ): array {
        $headers = [];
        if ($includeXpt) {
            $headers[] = 'X-PT: 1';
        }
        if ($includeCsrf && $this->csrfToken !== '') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }

        $payload = '';
        if (is_array($body)) {
            $headers[] = 'Content-Type: application/json';
            $payload = (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (is_string($body)) {
            $payload = $body;
        }
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "{$k}: {$v}";
        }

        $parts = parse_url($route);
        $path = $parts['path'] ?? $route;
        $query = isset($parts['query']) ? '&' . $parts['query'] : '';
        return $this->send($method, $this->baseUrl . '/api.php?route=' . urlencode($path) . $query, $headers, $payload);
    }

    /** Multipart upload of one file field (default field name: "file"). */
    public function upload(string $route, string $fileName, string $content, string $mime, array $fields = [], string $field = 'file', array $extraHeaders = []): array
    {
        $boundary = '----ptreg' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $k => $v) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$fileName}\"\r\n"
            . "Content-Type: {$mime}\r\n\r\n{$content}\r\n--{$boundary}--\r\n";

        return $this->request('POST', $route, $body, array_merge(['Content-Type' => "multipart/form-data; boundary={$boundary}"], $extraHeaders));
    }

    /** Log in through the real /api/login endpoint. Returns the response. */
    public function login(string $name, string $pin): array
    {
        return $this->request('POST', '/api/login', ['name' => $name, 'pin' => $pin]);
    }

    /** Fetch a non-API path (e.g. /public/index.html) to inspect headers/body. */
    public function get(string $path): array
    {
        return $this->send('GET', $this->baseUrl . $path, [], '');
    }

    public function send(string $method, string $url, array $headers = [], string $payload = ''): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        $headerMap = [];
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
            } elseif (str_contains($line, ':')) {
                [$hk, $hv] = explode(':', $line, 2);
                $hkLower = strtolower(trim($hk));
                $hvTrim = trim($hv);
                if ($hkLower === 'set-cookie') {
                    $pair = explode(';', $hvTrim)[0];
                    $this->cookie = (str_ends_with($pair, '=') || str_contains($hvTrim, 'Max-Age=0')) ? '' : $pair;
                }
                $headerMap[$hkLower] = $hvTrim;
            }
        }

        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($json) && isset($json['csrfToken']) && is_string($json['csrfToken'])) {
            $this->csrfToken = $json['csrfToken'];
        }

        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string)$raw, 'headers' => $headerMap];
    }
}

