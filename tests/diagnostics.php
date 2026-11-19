<?php

declare(strict_types=1);

use App\Diagnostics\AppLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = dirname(__DIR__) . '/var/travel-sync-log-' . bin2hex(random_bytes(8)) . '.log';
$logger = new AppLogger($path);
$logger->write('security.test', [
    'error' => 'Request failed: access_token=secret-access&client_secret=secret-client Authorization: Bearer secret-bearer',
    'password' => 'secret-password',
]);
$contents = (string) file_get_contents($path);
@unlink($path);

foreach (['secret-access', 'secret-client', 'secret-bearer', 'secret-password'] as $secret) {
    if (str_contains($contents, $secret)) {
        throw new RuntimeException('Diagnostic logger exposed a secret.');
    }
}
if (!str_contains($contents, '[redacted]')) {
    throw new RuntimeException('Diagnostic logger did not mark redacted values.');
}

echo "Diagnostic redaction checks passed.\n";
