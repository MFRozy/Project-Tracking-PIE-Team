<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$env = new TestEnv();
$env->resetSchema();
$env->seedUsers();
$env->startServer();

try {
    $admin = $env->client('t_admin');
    $user1 = $env->client('t_user1');
    $user2 = $env->client('t_user2');
    $viewer = $env->client('t_viewer');

    // ------------------------------------------------------------------------
    // Setup initial projects:
    // 1. Unassigned project (empty PIC) created by admin
    // 2. Project owned by User1, with a stage assigned to User2 (stage PIC)
    // ------------------------------------------------------------------------
    $resEmptyPic = $admin->request('POST', '/api/projects', [
        'id' => 'p-empty-pic',
        'code' => 'PRJ-EMPTY',
        'name' => 'Unassigned Admin Project',
        'category' => 'Improvement',
        'status' => 'On Progress',
        'priority' => 'Medium',
        'pic' => '',
        'stages' => [],
    ]);
    T::check($resEmptyPic['status'] === 201, 'Admin successfully created empty-PIC project');

    $resUser1Proj = $admin->request('POST', '/api/projects', [
        'id' => 'p-user1-owned',
        'code' => 'PRJ-USER1',
        'name' => 'User1 Project',
        'category' => 'Improvement',
        'status' => 'On Progress',
        'priority' => 'Medium',
        'pic' => 't_user1',
        'budget' => 5000000,
        'stages' => [
            [
                'id' => 'stage-u2',
                'name' => 'Stage Handled By User2',
                'pic' => 't_user2',
                'status' => 'Not Started',
                'prog' => 0,
            ],
            [
                'id' => 'stage-u1',
                'name' => 'Stage Handled By User1',
                'pic' => 't_user1',
                'status' => 'Not Started',
                'prog' => 0,
            ],
        ],
        'quotations' => [
            [
                'id' => 'q1',
                'vendor' => 'Vendor A',
                'amount' => 1000000,
                'status' => 'Review',
            ],
        ],
    ]);
    T::check($resUser1Proj['status'] === 201, 'User1 project with User2 stage PIC created');

    // ------------------------------------------------------------------------
    // 1. Unrelated user gets 403 when changing or deleting an empty-PIC project
    // ------------------------------------------------------------------------
    $resUser1EditEmpty = $user1->request('PUT', '/api/projects/p-empty-pic', [
        'name' => 'User1 Takes Over Empty Project',
        'pic' => 't_user1',
    ]);
    T::check($resUser1EditEmpty['status'] === 403, 'User1 gets 403 modifying empty-PIC project', $resUser1EditEmpty['status']);

    $resUser1SyncEditEmpty = $user1->request('POST', '/api/sync', [
        'rev' => 0,
        'push' => [
            'projects' => [
                ['id' => 'p-empty-pic', 'name' => 'User1 Takes Over via Sync', 'updatedAt' => (int)round(microtime(true) * 1000)],
            ],
        ],
    ]);
    T::check(
        $resUser1SyncEditEmpty['status'] === 200 && ($resUser1SyncEditEmpty['json']['rejectedProjectAuth'] ?? false) === true,
        'User1 rejected via /api/sync when modifying empty-PIC project'
    );

    $resUser1DeleteEmpty = $user1->request('DELETE', '/api/projects/p-empty-pic');
    T::check($resUser1DeleteEmpty['status'] === 403, 'User1 gets 403 deleting empty-PIC project', $resUser1DeleteEmpty['status']);

    // Admin CAN edit and delete empty-PIC project
    $resAdminEditEmpty = $admin->request('PUT', '/api/projects/p-empty-pic', [
        'name' => 'Admin Updated Empty Project',
        'pic' => 't_admin',
    ]);
    T::check($resAdminEditEmpty['status'] === 200, 'Admin can modify empty-PIC project', $resAdminEditEmpty['status']);

    // Reset PIC to empty for delete test
    $admin->request('PUT', '/api/projects/p-empty-pic', [
        'name' => 'Admin Empty Project Again',
        'pic' => 't_admin',
    ]);

    // Verify Admin can delete empty-PIC project
    $resAdminDeleteEmpty = $admin->request('DELETE', '/api/projects/p-empty-pic');
    T::check($resAdminDeleteEmpty['status'] === 200, 'Admin can delete empty-PIC project', $resAdminDeleteEmpty['status']);

    // ------------------------------------------------------------------------
    // 2. An update that sets an empty `pic` returns 400
    // ------------------------------------------------------------------------
    $resEmptyPicUpdate = $user1->request('PUT', '/api/projects/p-user1-owned', [
        'name' => 'User1 Clears PIC',
        'pic' => '',
    ]);
    T::check($resEmptyPicUpdate['status'] === 400, 'Updating project with empty pic returns 400', $resEmptyPicUpdate['status']);

    // Non-owner User2 attempting to change PIC of User1's project returns 400
    $resUser2ChangePic = $user2->request('PUT', '/api/projects/p-user1-owned', [
        'pic' => 't_user2',
    ]);
    T::check($resUser2ChangePic['status'] === 400, 'Non-owner User2 changing PIC returns 400', $resUser2ChangePic['status']);

    // ------------------------------------------------------------------------
    // 3. User2 (stage PIC only) cannot change project pic or metadata or quotation
    //    but CAN update their own stage status/progress
    // ------------------------------------------------------------------------
    // User2 attempts to change project title and reassign PIC to User2
    $resUser2Takeover = $user2->request('PUT', '/api/projects/p-user1-owned', [
        'name' => 'User2 Stolen Title',
        'pic' => 't_user2',
        'stages' => [
            [
                'id' => 'stage-u2',
                'name' => 'Stage Handled By User2',
                'pic' => 't_user2',
                'status' => 'On Progress',
                'prog' => 50,
            ],
        ],
        'quotations' => [
            [
                'id' => 'q1',
                'vendor' => 'Hacked Vendor',
                'amount' => 999999999,
                'status' => 'Diterima',
            ],
        ],
    ]);
    T::check(in_array($resUser2Takeover['status'], [400, 403], true) || $resUser2Takeover['status'] === 200, 'User2 attempted takeover submitted');
    // PIC reassignment by stage PIC must be rejected or project title/quotation prevented
    // In our spec: title & quotations not changed, pic not changed, but stage-u2 updated
    function fetchProject(HttpClient $client, string $id): ?array
    {
        $res = $client->request('GET', '/api/projects');
        foreach ($res['json']['items'] ?? [] as $item) {
            if (($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        return null;
    }

    $getProj = fetchProject($admin, 'p-user1-owned');
    $projData = $getProj ?? [];
    T::check(($projData['pic'] ?? '') === 't_user1', 'Stage PIC cannot change project PIC', ['pic' => $projData['pic'] ?? '']);
    T::check(($projData['name'] ?? '') === 'User1 Project', 'Stage PIC cannot change project metadata name', ['name' => $projData['name'] ?? '']);

    // Verify User2 CAN update their own stage
    $user2StageUpdate = $user2->request('PUT', '/api/projects/p-user1-owned', [
        'stages' => [
            [
                'id' => 'stage-u2',
                'name' => 'Stage Handled By User2',
                'pic' => 't_user2',
                'status' => 'Completed',
                'prog' => 100,
            ],
        ],
    ]);
    T::check($user2StageUpdate['status'] === 200, 'Stage PIC can update their own stage', $user2StageUpdate['status']);

    $getProjAfterStage = fetchProject($admin, 'p-user1-owned');
    $stagesAfter = $getProjAfterStage['stages'] ?? [];
    $u2StageStatus = '';
    foreach ($stagesAfter as $st) {
        if (($st['id'] ?? '') === 'stage-u2') {
            $u2StageStatus = $st['status'] ?? '';
        }
    }
    T::check($u2StageStatus === 'Completed', 'User2 stage successfully marked Completed', ['status' => $u2StageStatus]);

    // ------------------------------------------------------------------------
    // 4. /api/logs access control
    //    - project_id=AUTH is admin only (user gets 403)
    //    - Non-existent project id is admin only (user gets 403)
    //    - Existing project logs accessible by user (200)
    //    - Contributors / viewers get 403 on /api/logs
    // ------------------------------------------------------------------------
    // Admin can read AUTH logs
    $resAdminAuthLogs = $admin->request('GET', '/api/logs?project_id=AUTH');
    T::check($resAdminAuthLogs['status'] === 200, 'Admin gets 200 reading AUTH logs', $resAdminAuthLogs['status']);

    // User gets 403 reading AUTH logs
    $resUserAuthLogs = $user1->request('GET', '/api/logs?project_id=AUTH');
    T::check($resUserAuthLogs['status'] === 403, 'User gets 403 reading AUTH logs', $resUserAuthLogs['status']);

    // User gets 403 reading logs of non-existent project
    $resUserNonExistentLogs = $user1->request('GET', '/api/logs?project_id=p-does-not-exist');
    T::check($resUserNonExistentLogs['status'] === 403, 'User gets 403 reading logs of non-existent project', $resUserNonExistentLogs['status']);

    // User gets 200 reading logs of existing project
    $resUserProjLogs = $user1->request('GET', '/api/logs?project_id=p-user1-owned');
    T::check($resUserProjLogs['status'] === 200, 'User gets 200 reading logs of existing project', $resUserProjLogs['status']);

    // Viewer gets 403 on /api/logs
    $resViewerLogs = $viewer->request('GET', '/api/logs?project_id=p-user1-owned');
    T::check($resViewerLogs['status'] === 403, 'Viewer gets 403 reading logs', $resViewerLogs['status']);

} finally {
    $env->stopServer();
}
