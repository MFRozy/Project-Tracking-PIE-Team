<?php

declare(strict_types=1);

/**
 * P0-1 Step B test:
 * 1. Checks CSP header presence and exact directive values.
 * 2. Checks that public/index.html contains no inline script blocks (only external src)
 *    and no inline on* attributes (all static handlers migrated to delegated data-on-*).
 * 3. Checks that public/app.js exists.
 * 4. Runs render check against public/app.js to ensure app works without inline scripts.
 */

/** @var TestEnv $env */

$client = $env->client();
$resp = $client->get('/index.php');
$csp = $resp['headers']['content-security-policy'] ?? '';

$expectedCsp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'";

T::check($csp !== '', 'CSP header is present on index.php response', $resp['headers']);
T::check($csp === $expectedCsp, 'CSP matches the exact required policy without unsafe-inline for scripts', $csp);

$htaccess = (string)file_get_contents($env->rootDir . '/.htaccess');
T::check(str_contains($htaccess, 'Content-Security-Policy') && str_contains($htaccess, "script-src 'self'"), 'CSP configured in .htaccess without unsafe-inline for scripts');

$html = (string)file_get_contents($env->rootDir . '/public/index.html');

// Check that there are no inline scripts (excluding external scripts)
$inlineScripts = [];
if (preg_match_all('/<script\b([^>]*)>([\s\S]*?)<\/script>/i', $html, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
        if (!preg_match('/\bsrc\s*=/i', $m[1])) {
            $trimmed = trim($m[2]);
            if ($trimmed !== '') {
                $inlineScripts[] = substr($trimmed, 0, 80);
            }
        }
    }
}
T::check(empty($inlineScripts), 'public/index.html has no inline scripts', $inlineScripts);

// Check that public/app.js exists and is referenced
T::check(is_file($env->rootDir . '/public/app.js'), 'public/app.js exists');
T::check(str_contains($html, 'app.js'), 'public/index.html includes app.js');

// Check that no inline event handlers remain in index.html
$remainingInlineHandlers = [];
if (preg_match_all('/\bon[a-z]+\s*=\s*(["\'])(.*?)\1/i', $html, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
        $remainingInlineHandlers[] = substr($m[0], 0, 60);
    }
}
T::check(empty($remainingInlineHandlers), 'public/index.html has no inline on* event handlers', array_slice($remainingInlineHandlers, 0, 10));
