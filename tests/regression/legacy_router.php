<?php

declare(strict_types=1);

/**
 * Router for php -S when testing legacy DocumentRoot (repo root).
 * Enforces root .htaccess security rules in PHP built-in server.
 */
$uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// 1. Block sensitive root files and extensions
if (
    preg_match('#(?:^|/)(?:\.env|config\.php|db\.php|schema\.sql|api\.php)(?:$|/)#i', $uri) ||
    preg_match('#\.(bak|conf|dist|env|json|log|md|sh|sql|txt|bat)$#i', $uri) ||
    preg_match('#^/(?:storage|data|tests|migrations|\.git)(?:/|$)#i', $uri)
) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Forbidden';
    exit;
}

// 2. Allow direct access to public/ files
if (str_starts_with($uri, '/public/')) {
    $target = dirname(__DIR__, 2) . $uri;
    if (is_file($target)) {
        return false; // let php -S serve it
    }
}

// 3. Route /api/...
if (preg_match('#^/api/(.*)$#', $uri, $matches)) {
    $_GET['route'] = '/api/' . $matches[1];
    require dirname(__DIR__, 2) . '/public/index.php';
    exit;
}

// 4. Route root or missing to public/index.php
if ($uri === '/' || $uri === '/index.php') {
    require dirname(__DIR__, 2) . '/public/index.php';
    exit;
}

return false;

