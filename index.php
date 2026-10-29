<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$html = file_get_contents(__DIR__ . '/public/index.html');
if ($html === false) {
    http_response_code(500);
    exit('Gagal memuat antarmuka aplikasi.');
}

$html = str_replace('src="/shared.js"', 'src="public/shared.js"', $html);
echo $html;
