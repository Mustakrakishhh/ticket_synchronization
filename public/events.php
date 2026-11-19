<?php

declare(strict_types=1);

use App\Application;
use App\Bitrix24\Bitrix24Client;
use App\OAuth\InstallationRequest;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$request = InstallationRequest::fromGlobals();
$event = strtoupper((string) ($_POST['event'] ?? $_GET['event'] ?? ''));
$memberId = (string) ($request['member_id'] ?? '');
$applicationToken = (string) ($request['application_token'] ?? '');

if (!in_array($event, ['ONAPPUNINSTALL', 'ONAPPUPDATE'], true) || $memberId === '') {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

if ($event === 'ONAPPUNINSTALL') {
    if (!$app->repository->verifyApplicationToken($memberId, $applicationToken)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    $app->repository->purgeTenant($memberId);
    echo 'ok';
    exit;
}

try {
    $created = $app->oauth->fromPlacement($request, false);
    $tenant = $app->repository->tenant($memberId);
    if ($tenant === null
        || ($tenant['status'] ?? null) !== 'active'
    ) {
        throw new RuntimeException('Unknown Bitrix24 installation.');
    }
    // A portal may be renamed. The signed OAuth context and app.info call are
    // authoritative; an old stored domain must not block token recovery.
    (new Bitrix24Client($created['service']))->call('app.info');
    $app->repository->saveTenant($memberId, $created['record']);
    $app->repository->audit($memberId, null, 'app.updated', [
        'version' => (string) (is_array($_POST['data'] ?? null) ? ($_POST['data']['VERSION'] ?? '') : ''),
        'previousVersion' => (string) (is_array($_POST['data'] ?? null) ? ($_POST['data']['PREVIOUS_VERSION'] ?? '') : ''),
    ]);
} catch (Throwable) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}
echo 'ok';
