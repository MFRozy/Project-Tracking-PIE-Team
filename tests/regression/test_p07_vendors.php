<?php

declare(strict_types=1);

/** @var TestEnv $env */

$user = $env->client('t_user1');
$settingsAt = (int)(microtime(true) * 1000);
$res = $user->request('POST', '/api/sync', [
    'rev' => -1,
    'sa' => 0,
    'push' => ['settings' => [
        'settingsAt' => $settingsAt,
        'vendors' => [
            [
                'id' => 'vendor-test-1',
                'name' => 'Vendor Master Test',
                'contact' => 'Test Contact',
                'phone' => '+62 21 555000',
                'email' => 'vendor@example.test',
                'address' => 'Test Address',
            ],
            [
                'id' => 'vendor-test-2',
                'name' => ' vendor master test ',
                'contact' => 'Duplicate',
            ],
        ],
    ]],
]);
T::check($res['status'] === 200, 'Editor can save shared vendor profiles', $res['status']);
T::check(
    ($res['json']['settings']['vendors'][0]['name'] ?? '') === 'Vendor Master Test',
    'Shared vendor profile is returned through sync to the team'
);

$stored = $env->pdo->query("SELECT value_json FROM settings WHERE key_name = 'tracker'")->fetchColumn();
$settings = is_string($stored) ? json_decode($stored, true) : [];
$vendors = $settings['vendors'] ?? [];
T::check(
    count($vendors) === 1
        && ($vendors[0]['name'] ?? '') === 'Vendor Master Test'
        && ($vendors[0]['contact'] ?? '') === 'Test Contact'
        && ($vendors[0]['address'] ?? '') === 'Test Address',
    'Vendor profiles are normalized and persist shared contact details',
    $vendors
);
