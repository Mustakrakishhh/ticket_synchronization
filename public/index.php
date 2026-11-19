<?php

declare(strict_types=1);

use App\Application;
use App\OAuth\InstallationRequest;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors https:");

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$error = null;
$session = null;

try {
    $request = InstallationRequest::fromGlobals();
    if (isset($request['AUTH_ID'], $request['REFRESH_ID'], $request['DOMAIN'], $request['member_id'])) {
        $created = $app->oauth->fromPlacement($request, false);
        $placementClient = new App\Bitrix24\Bitrix24Client($created['service']);
        $user = $placementClient->currentUser();
        if (empty($user['admin'])) {
            throw new RuntimeException('Настройка Travel Sync доступна только администратору Bitrix24.');
        }
        $tenant = $app->repository->tenant($created['memberId']);
        $storedRecord = null;
        try {
            $storedRecord = $tenant !== null ? $app->repository->token($created['memberId']) : null;
        } catch (Throwable) {
            $storedRecord = null;
        }
        $normalizeScopes = static function (string $scope): array {
            $scopes = array_values(array_unique(array_filter(array_map(
                static fn (string $item): string => strtolower(trim($item)),
                explode(',', $scope),
            ))));
            sort($scopes);
            return $scopes;
        };
        $capabilitiesCheckedAt = strtotime((string) ($storedRecord['capabilities_checked_at'] ?? '')) ?: 0;
        $storedCapabilities = is_array($storedRecord['capabilities'] ?? null)
            ? $storedRecord['capabilities']
            : null;
        $canReuseCapabilities = $storedCapabilities !== null
            && $capabilitiesCheckedAt >= time() - 21600
            && strtolower((string) ($tenant['domain'] ?? '')) === strtolower((string) $created['record']['domain'])
            && $normalizeScopes((string) ($storedRecord['scope'] ?? '')) === $normalizeScopes($app->config->scope);
        $capabilities = $canReuseCapabilities
            ? $storedCapabilities
            : $placementClient->capabilities([
                'crm.item.add',
                'crm.item.update',
                'im.notify.system.add',
            ]);
        $created['record']['capabilities'] = $capabilities;
        $created['record']['capabilities_checked_at'] = $canReuseCapabilities
            ? (string) $storedRecord['capabilities_checked_at']
            : gmdate(DATE_ATOM);
        $created['record']['capabilities_token_fingerprint'] = $created['record']['token_fingerprint'] ?? null;
        foreach (['crm.item.add', 'crm.item.update'] as $requiredMethod) {
            if (empty($capabilities[$requiredMethod]['isAvailable'])) {
                throw new RuntimeException(
                    "Метод {$requiredMethod} недоступен OAuth-токену. "
                    . 'Добавьте право crm в настройках локального приложения Bitrix24 и откройте приложение снова.'
                );
            }
        }

        if ($tenant === null) {
            if ($app->config->distribution !== 'local') {
                throw new RuntimeException('Установка портала не найдена. Переустановите приложение из Bitrix24 Маркета.');
            }
        } elseif (($tenant['status'] ?? null) !== 'active') {
            throw new RuntimeException('Установка приложения отключена. Выполните повторную установку в Bitrix24.');
        }

        $domainChanged = $tenant === null || !hash_equals(
            strtolower((string) ($tenant['domain'] ?? '')),
            strtolower((string) $created['record']['domain']),
        );
        $scopeChanged = false;
        try {
            $scopeChanged = $normalizeScopes((string) ($storedRecord['scope'] ?? ''))
                !== $normalizeScopes($app->config->scope);
        } catch (Throwable) {
            $scopeChanged = true;
        }
        // A placement token has just been verified with app.info, administrator identity
        // and the required CRM methods. Persist it on every app open so a still-valid but
        // scope-limited stored token cannot survive merely because app.info succeeds.
        $app->repository->saveTenant($created['memberId'], $created['record']);
        $app->repository->audit($created['memberId'], $user['id'], 'oauth.placement_saved', [
            'reason' => $tenant === null
                ? 'tenant_record_missing'
                : ($domainChanged
                    ? 'portal_domain_changed'
                    : ($scopeChanged ? 'application_scope_changed' : 'fresh_placement_context')),
            'tokenFingerprint' => $created['record']['token_fingerprint'] ?? null,
            'scope' => $created['record']['scope'] ?? '',
            'scopeSource' => $created['record']['scope_source'] ?? '',
            'capabilitiesReused' => $canReuseCapabilities,
            'capabilities' => $capabilities,
        ]);
        $app->repository->recordUser($created['memberId'], $user['id'], $user['name'], true);
        $app->repository->audit($created['memberId'], $user['id'], 'app.opened');
        $session = $app->sessions->issue($created['memberId'], $user['id'], true);
    } else {
        $session = $app->sessions->requireValid();
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Travel Sync</title>
  <link rel="stylesheet" href="assets/app.css?v=12">
</head>
<body>
<main class="app-shell">
  <header class="app-header">
    <div class="brand"><span class="brand-mark">TS</span><div><p class="eyebrow">Bitrix24</p><h1>Travel Sync</h1></div></div>
    <div class="header-status"><span class="pulse"></span> Настройка интеграции</div>
  </header>

  <?php if ($error !== null): ?>
    <section class="panel error-panel"><h2>Приложение не авторизовано</h2><p><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p></section>
  <?php else: ?>
    <nav class="steps" aria-label="Этапы настройки">
      <button class="step active" data-step="1"><span>1</span>Провайдеры</button>
      <button class="step" data-step="2"><span>2</span>Смарт-процесс</button>
      <button class="step" data-step="3"><span>3</span>Воронки</button>
      <button class="step" data-step="4"><span>4</span>Поля</button>
      <button class="step" data-step="5"><span>5</span>Затраты</button>
      <button class="step" data-step="6"><span>6</span>Запуск</button>
    </nav>

    <section class="workspace">
      <div class="workspace-copy">
        <p class="eyebrow" id="step-kicker">Шаг 1 из 4</p>
        <h2 id="step-title">Подключите тревел-сервисы</h2>
        <p id="step-description">Можно подключить один сервис или несколько. Секреты шифруются отдельно для вашего портала.</p>
      </div>
      <div id="screen" class="screen" aria-live="polite"><div class="loader">Загружаем настройки…</div></div>
      <footer class="wizard-actions">
        <button class="button ghost" id="back" disabled>Назад</button>
        <p id="save-status" class="muted"></p>
        <button class="button primary" id="next">Продолжить</button>
      </footer>
    </section>
  <?php endif; ?>
</main>
<?php if ($error === null): ?>
<script>
window.TRAVEL_SYNC_BOOT = <?= json_encode([
    'csrf' => $session['csrf'],
    'memberId' => $session['member'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?>;
</script>
<script src="assets/app.js?v=31" defer></script>
<?php endif; ?>
</body>
</html>
