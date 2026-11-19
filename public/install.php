<?php

declare(strict_types=1);

use App\Application;
use App\Bitrix24\Bitrix24Client;
use App\OAuth\InstallationRequest;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://api.bitrix24.com; style-src 'self'; frame-ancestors https:");

$success = false;
$needsBrowserAuth = false;
$message = '';

try {
    /** @var Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';
    $request = InstallationRequest::fromGlobals();
    if (!isset($request['AUTH_ID'], $request['REFRESH_ID'], $request['DOMAIN'], $request['member_id'])) {
        $needsBrowserAuth = true;
        $message = 'Получаю OAuth-данные из контекста Bitrix24…';
    } else {
        $created = $app->oauth->fromPlacement($request, false);
        $client = new Bitrix24Client($created['service']);
        $client->call('app.info');
        $user = $client->currentUser();
        if (empty($user['admin'])) {
            throw new RuntimeException('Установить Travel Sync может только администратор Bitrix24.');
        }
        $capabilities = $client->capabilities([
            'crm.item.add',
            'crm.item.update',
            'im.notify.system.add',
        ]);
        $created['record']['capabilities'] = $capabilities;
        $created['record']['capabilities_checked_at'] = gmdate(DATE_ATOM);
        $created['record']['capabilities_token_fingerprint'] = $created['record']['token_fingerprint'] ?? null;
        foreach (['crm.item.add', 'crm.item.update'] as $requiredMethod) {
            if (empty($capabilities[$requiredMethod]['isAvailable'])) {
                throw new RuntimeException(
                    "Метод {$requiredMethod} недоступен OAuth-токену. "
                    . 'Добавьте право crm в настройках локального приложения Bitrix24 и повторите установку.'
                );
            }
        }
        $app->repository->saveTenant($created['memberId'], $created['record']);
        $app->repository->recordUser($created['memberId'], $user['id'], $user['name'], true);
        $app->repository->audit($created['memberId'], $user['id'], 'app.installed', [
            'domain' => $created['record']['domain'],
            'tokenFingerprint' => $created['record']['token_fingerprint'] ?? null,
            'capabilities' => $capabilities,
        ]);
        $handler = $app->config->publicUrl . '/events.php';
        foreach (['ONAPPUNINSTALL', 'ONAPPUPDATE'] as $event) {
            $client->call('event.unbind', ['event' => $event, 'handler' => $handler]);
            $client->call('event.bind', ['event' => $event, 'handler' => $handler]);
        }
        $app->sessions->issue($created['memberId'], $user['id'], true);
        $success = true;
        $message = 'Портал подключён. Можно переходить к настройке провайдеров.';
    }
} catch (Throwable $exception) {
    $retryAttempted = (string) (InstallationRequest::fromGlobals()['OAUTH_RETRY'] ?? '') === '1';
    if (!$retryAttempted && str_contains(strtoupper($exception->getMessage()), 'UNAUTHORIZED')) {
        $needsBrowserAuth = true;
        $message = 'OAuth-токен устарел. Запрашиваю новый…';
    } else {
        $message = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Установка Travel Sync</title>
  <link rel="stylesheet" href="assets/app.css?v=11">
  <?php if ($success || $needsBrowserAuth): ?>
    <script src="https://api.bitrix24.com/api/v1/"></script>
  <?php endif; ?>
</head>
<body class="install-page">
<main class="install-shell">
  <section class="install-card">
    <span class="brand-mark">TS</span>
    <p class="eyebrow">Bitrix24 Travel Sync</p>
    <h1><?= $success ? 'Приложение установлено' : ($needsBrowserAuth ? 'Подключаем портал' : 'Установка не завершена') ?></h1>
    <p id="install-message"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php if ($success): ?><p class="muted">Закрываем мастер установки…</p><?php endif; ?>
  </section>
</main>
<?php if ($success): ?>
<script>
if (window.BX24 && typeof BX24.init === 'function') {
  BX24.init(function () { BX24.installFinish(); });
} else {
  document.getElementById('install-message').textContent =
    'Откройте установщик из интерфейса Bitrix24.';
}
</script>
<?php elseif ($needsBrowserAuth): ?>
<script>
if (!window.BX24 || typeof BX24.init !== 'function') {
  document.getElementById('install-message').textContent =
    'Откройте установщик из интерфейса Bitrix24.';
} else BX24.init(function () {
  BX24.refreshAuth(function (auth) {
    if (!auth || !auth.access_token || !auth.refresh_token || !auth.domain || !auth.member_id) {
      document.getElementById('install-message').textContent =
        'Bitrix24 не передал полный OAuth-контекст. Откройте установщик от имени администратора.';
      return;
    }
    const form = document.createElement('form');
    form.method = 'post';
    form.action = location.href;
    const fields = {
      AUTH_ID: auth.access_token,
      REFRESH_ID: auth.refresh_token,
      AUTH_EXPIRES: auth.expires_in instanceof Date
        ? Math.floor(auth.expires_in.getTime() / 1000)
        : (auth.expires_in || auth.expires || 3600),
      DOMAIN: auth.domain,
      member_id: auth.member_id,
      scope: auth.scope || '',
      application_token: auth.application_token || '',
      OAUTH_RETRY: '1'
    };
    Object.entries(fields).forEach(([name, value]) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = String(value);
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  });
});
</script>
<?php endif; ?>
</body>
</html>
