<?php

declare(strict_types=1);

$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = (string)(parse_url($requestUri, PHP_URL_PATH) ?: '/');
$routeParam = $_GET['route'] ?? null;

// 1. Route API requests
if (
    (is_string($routeParam) && $routeParam !== '') ||
    str_starts_with($requestPath, '/api/') ||
    preg_match('#(?:^|/)api\.php#', $requestPath) === 1
) {
    require dirname(__DIR__) . '/api.php';
    exit;
}

// 2. Under php -S, serve existing static files directly
$filePath = __DIR__ . $requestPath;
if ($requestPath !== '/' && $requestPath !== '/index.php' && is_file($filePath)) {
    return false;
}

// 3. Return 404 for requests specifying a file extension or dotfile that does not exist
if ($requestPath !== '/' && $requestPath !== '/index.php') {
    $ext = pathinfo($requestPath, PATHINFO_EXTENSION);
    if ($ext !== '' || str_starts_with(basename($requestPath), '.')) {
        http_response_code(404);
        header('Content-Type: text/plain');
        exit('Not Found');
    }
}

// 3. Serve Frontend SPA with strict security headers
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

$html = file_get_contents(__DIR__ . '/index.html');
if ($html === false) {
    http_response_code(500);
    exit('Gagal memuat antarmuka aplikasi.');
}

// When accessed through legacy root (/public/...), adjust script paths
if (str_contains($requestUri, '/public')) {
    $html = str_replace(['src="/shared.js"', 'src="/lib/i18n.js"', 'src="/app.js"'], ['src="public/shared.js"', 'src="public/lib/i18n.js"', 'src="public/app.js"'], $html);
    $html = str_replace(["url('/cosmax-mark.svg')", 'href="/cosmax-mark.svg"'], ["url('public/cosmax-mark.svg')", 'href="public/cosmax-mark.svg"'], $html);
}

echo $html;
