<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/HttpClient.php';

$options = getopt('', ['base-urls:', 'user:', 'pin:', 'mode:', 'iterations:', 'client-id:', 'barrier:']);
$baseUrls = explode(',', (string)($options['base-urls'] ?? 'http://127.0.0.1:8000'));
$user = (string)($options['user'] ?? 't_user1');
$pin = (string)($options['pin'] ?? 'User1-Test-1');
$mode = (string)($options['mode'] ?? 'poll');
$iterations = (int)($options['iterations'] ?? 15);
$clientId = (int)($options['client-id'] ?? 1);
$barrierFile = (string)($options['barrier'] ?? '');

$statuses = [];
$errors = [];
$urlCount = count($baseUrls);
$currentRev = 0;
$currentSa = 0;

$client = new HttpClient($baseUrls[0]);
$loginRes = $client->login($user, $pin);
if ($loginRes['status'] !== 200) {
    echo json_encode([
        'clientId' => $clientId,
        'fatal' => "Login failed: HTTP {$loginRes['status']}",
        'statuses' => [$loginRes['status']],
        'errors' => ["Login failed"],
    ]);
    exit(1);
}

// If a barrier file is specified, signal ready and wait for release to ensure concurrent burst
if ($barrierFile !== '') {
    $readyFile = $barrierFile . ".ready.{$clientId}";
    touch($readyFile);
    while (file_exists($barrierFile . '.wait')) {
        usleep(5000); // 5ms poll
    }
}

for ($i = 1; $i <= $iterations; $i++) {
    // Pick next server URL in round-robin fashion
    $url = $baseUrls[($clientId + $i) % $urlCount];
    $client->baseUrl = $url;

    if ($mode === 'poll') {
        $body = [
            'rev' => $currentRev,
            'sa' => $currentSa,
            'push' => new stdClass(),
        ];
    } else {
        $body = [
            'rev' => $currentRev,
            'sa' => $currentSa,
            'push' => [
                'projects' => [
                    [
                        'id' => sprintf('p-c%02d', $clientId),
                        'code' => sprintf('PRJ-C%02d', $clientId),
                        'name' => sprintf('Concurrent Project C%02d', $clientId),
                        'category' => 'Improvement',
                        'status' => 'On Progress',
                        'priority' => 'Medium',
                        'pic' => $user,
                        'area' => 'Line 1',
                        'startDate' => '2026-01-01',
                        'endDate' => '2026-12-31',
                        'progress' => min(100, $i * 5),
                        'description' => "Iteration {$i}",
                        'vendor' => '',
                        'budget' => 1000000,
                        'actual' => 0,
                        'po' => '',
                        'metadata' => ['tags' => []],
                        'stages' => [],
                        'milestones' => [],
                        'quotations' => [],
                        'pos' => [],
                        'updatedAt' => (int)round(microtime(true) * 1000),
                    ],
                ],
            ],
        ];
    }

    $res = $client->request('POST', '/api/sync', $body);
    $status = $res['status'];
    $statuses[] = $status;

    if ($status === 200) {
        if (isset($res['json']['rev'])) {
            $currentRev = (int)$res['json']['rev'];
        }
        if (isset($res['json']['settingsAt'])) {
            $currentSa = (int)$res['json']['settingsAt'];
        }
    } elseif ($status === 503) {
        $retryAfter = (int)($res['headers']['retry-after'] ?? 1);
        usleep(max(100000, $retryAfter * 1000000));
        // Retry once on 503
        $res = $client->request('POST', '/api/sync', $body);
        $statuses[] = $res['status'];
        if ($res['status'] === 200) {
            if (isset($res['json']['rev'])) {
                $currentRev = (int)$res['json']['rev'];
            }
        } else {
            $errors[] = "503 retry returned {$res['status']}";
        }
    } else {
        $errors[] = "HTTP {$status}: " . substr((string)$res['raw'], 0, 200);
    }
}

echo json_encode([
    'clientId' => $clientId,
    'statuses' => $statuses,
    'errors' => $errors,
    'finalRev' => $currentRev,
]);
