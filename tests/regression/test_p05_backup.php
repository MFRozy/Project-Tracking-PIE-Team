<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$env = new TestEnv();
$env->resetSchema();
$env->seedUsers();
// By default, PT_ENABLE_APP_BACKUP is NOT set (defaults to false)
$env->startServer();

try {
    $admin = $env->client('t_admin');
    $user1 = $env->client('t_user1');
    $contrib = $env->client('t_contrib');

    // ------------------------------------------------------------------------
    // 1. With flag off (default), /api/backup and /api/restore must return 403/404
    // ------------------------------------------------------------------------
    $resBackupDefault = $admin->request('POST', '/api/backup');
    T::check(in_array($resBackupDefault['status'], [403, 404], true), 'Default (flag off): /api/backup returns 403/404', $resBackupDefault['status']);

    $resRestoreDefault = $admin->request('POST', '/api/restore', ['projects' => []]);
    T::check(in_array($resRestoreDefault['status'], [403, 404], true), 'Default (flag off): /api/restore returns 403/404', $resRestoreDefault['status']);

    // ------------------------------------------------------------------------
    // 2. Upload recording in project_files (both PRJ and IMP files)
    // ------------------------------------------------------------------------
    // Create a project for user1
    $admin->request('POST', '/api/projects', [
        'id' => 'p-upload-test',
        'code' => 'PRJ-UPL',
        'name' => 'Upload Test Project',
        'category' => 'Improvement',
        'status' => 'On Progress',
        'priority' => 'Medium',
        'pic' => 't_user1',
        'stages' => [],
    ]);

    // Upload project attachment (PRJ)
    $prjPdf = "%PDF-1.4 sample project document";
    $resPrjUpload = $user1->upload('/api/files', 'doc1.pdf', $prjPdf, 'application/pdf', ['project_id' => 'p-upload-test']);
    T::check($resPrjUpload['status'] === 200, 'Project file upload succeeded', $resPrjUpload['status']);
    $prjStoredName = $resPrjUpload['json']['name'] ?? '';

    // Upload improvement attachment (IMP)
    $impPdf = "%PDF-1.4 sample improvement document";
    $resImpUpload = $contrib->upload('/api/files', 'imp1.pdf', $impPdf, 'application/pdf', [], 'file', ['X-Prefix' => 'IMP-TEST']);
    T::check($resImpUpload['status'] === 200, 'Improvement file upload succeeded', $resImpUpload['status']);
    $impStoredName = $resImpUpload['json']['name'] ?? '';

    // Verify both files exist in project_files table in database
    $stmt = $env->pdo->prepare('SELECT COUNT(*) FROM project_files WHERE stored_name = :s');
    $stmt->execute([':s' => $prjStoredName]);
    T::check((int)$stmt->fetchColumn() === 1, 'Project file has a row in project_files');

    $stmt = $env->pdo->prepare('SELECT COUNT(*) FROM project_files WHERE stored_name = :s');
    $stmt->execute([':s' => $impStoredName]);
    T::check((int)$stmt->fetchColumn() === 1, 'IMP-* improvement file has a row in project_files');

    // ------------------------------------------------------------------------
    // 3. Documentation checks: no false claims of full backup & restore
    // ------------------------------------------------------------------------
    $readme = (string)file_get_contents($env->rootDir . '/README.md');
    $panduan = (string)file_get_contents($env->rootDir . '/PANDUAN.txt');

    T::check(!str_contains(strtolower($readme), 'backup & restore penuh'), 'README.md does not claim "backup & restore penuh"');
    T::check(!str_contains(strtolower($panduan), 'backup lengkap'), 'PANDUAN.txt does not claim "backup lengkap"');

} finally {
    $env->stopServer();
}

// ----------------------------------------------------------------------------
// 4. Test with PT_ENABLE_APP_BACKUP=true: restore must preserve createdBy
// ----------------------------------------------------------------------------
// Restart server with PT_ENABLE_APP_BACKUP=true
putenv('PT_ENABLE_APP_BACKUP=true');
$envBackup = new TestEnv();
$envBackup->resetSchema();
$envBackup->seedUsers();
// We start server with PT_ENABLE_APP_BACKUP=1 in env
$serverEnv = [
    'PT_ENABLE_APP_BACKUP' => '1',
];
// Start server with custom env
$envBackup->startServer();

try {
    $admin = $envBackup->client('t_admin');

    // With flag enabled, /api/backup should succeed (200)
    $resBackupOn = $admin->request('POST', '/api/backup');
    T::check($resBackupOn['status'] === 200, 'With flag on: /api/backup returns 200', $resBackupOn['status']);

    // Test restore preserving createdBy
    $restorePayload = [
        'projects' => [
            [
                'id' => 'p-restore-1',
                'code' => 'PRJ-RESTORE',
                'name' => 'Restored Project',
                'category' => 'Improvement',
                'status' => 'On Progress',
                'priority' => 'Medium',
                'pic' => 't_user1',
                'metadata' => [
                    'createdBy' => 'Original_Author_Budi',
                    'stages' => [],
                    'milestones' => [],
                    'quotations' => [],
                    'pos' => [],
                ],
            ],
        ],
    ];

    $resRestore = $admin->request('POST', '/api/restore', $restorePayload);
    T::check($resRestore['status'] === 200, 'With flag on: /api/restore returns 200', $resRestore['status']);

    // Check project createdBy in database
    $stmt = $envBackup->pdo->prepare('SELECT metadata FROM projects WHERE id = :id');
    $stmt->execute([':id' => 'p-restore-1']);
    $metaRow = json_decode((string)$stmt->fetchColumn(), true);
    $restoredCreatedBy = $metaRow['createdBy'] ?? '';

    T::check(
        $restoredCreatedBy === 'Original_Author_Budi',
        'Restore preserves original createdBy (not overwritten by restoring admin)',
        ['createdBy' => $restoredCreatedBy]
    );

} finally {
    $envBackup->stopServer();
    putenv('PT_ENABLE_APP_BACKUP');
}
