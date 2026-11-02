<?php

declare(strict_types=1);

/**
 * P0-1 front end: runs tests/regression/dom/render_check.js against the fixture exported by
 * test_p01a_server_validation.php (marker data in every field). No browser is available, so this is a
 * DOM-level test of the real render functions with a stubbed DOM (see render_check.js).
 */

/** @var TestEnv $env */

$fixture = $env->storageDir . '/p01_fixture.json';
if (!is_file($fixture)) {
    T::check(false, 'render check needs the fixture from test_p01a_server_validation.php');
    return;
}

$proc = proc_open(
    ['node', __DIR__ . '/dom/render_check.js', $fixture],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $env->rootDir
);
if (!is_resource($proc)) {
    T::skip('DOM render check', 'node not available');
    return;
}
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

$pos = strrpos($out, '__REPORT__');
$report = $pos === false ? null : json_decode(substr($out, $pos + 10), true);
if (!is_array($report)) {
    T::check(false, 'DOM render check produced a report', substr($err ?: $out, 0, 500));
    return;
}
foreach ($report['checks'] as $c) {
    T::check((bool)$c['ok'], 'render: ' . $c['label'], $c['debug']);
}

