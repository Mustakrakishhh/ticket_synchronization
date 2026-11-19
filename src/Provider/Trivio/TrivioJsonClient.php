<?php

declare(strict_types=1);

namespace App\Provider\Trivio;

use RuntimeException;
use App\Domain\TravelDocument;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TrivioJsonClient
{
    private const ENDPOINT = 'https://corporate-api.trivio.ru/api/clientapi/json';
    private const API_TIMEZONE = 'Europe/Moscow';

    private HttpClientInterface $http;
    private ?string $sessionId = null;
    private string $endpoint = self::ENDPOINT;
    /** @var array<int, string> */
    private array $orderErrors = [];

    public function __construct(
        private readonly string $login,
        private readonly string $password,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create([
            'timeout' => 30,
            'max_duration' => 90,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Bitrix24-Travel-Sync/1.0',
            ],
        ]);
    }

    public function testConnection(): void
    {
        $this->authenticate();
    }

    /** The same read-only File API used by the Trivio cabinet for service_files. */
    public function downloadServiceFile(int $fileId): TravelDocument
    {
        if ($fileId <= 0) {
            throw new RuntimeException('Trivio не передал ID вложения услуги.');
        }
        $sessionId = $this->session();
        $endpoint = (string) preg_replace('~/clientapi/json$~', '/file/json', $this->endpoint);
        $result = $this->call('getFileContent', [
            'sessionId' => $sessionId,
            'fileId' => $fileId,
            'source' => 'service',
        ], $endpoint);
        $this->assertSuccessful($result, 'getFileContent');
        $file = is_array($result['file'] ?? null) ? $result['file'] : [];
        $encoded = (string) ($file['content'] ?? '');
        $maxBytes = 20 * 1024 * 1024;
        if (strlen($encoded) > (int) ceil($maxBytes * 4 / 3) + 4) {
            throw new RuntimeException("Вложение Trivio {$fileId} превышает допустимые 20 МБ.");
        }
        $content = base64_decode($encoded, true);
        if ($content === false || $content === '' || strlen($content) > $maxBytes) {
            throw new RuntimeException("Trivio вернул пустое или некорректное вложение {$fileId}.");
        }
        $contentType = match (true) {
            str_starts_with($content, '%PDF-') => 'application/pdf',
            str_starts_with($content, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($content, "\xff\xd8\xff") => 'image/jpeg',
            str_starts_with($content, "PK\x03\x04") => 'application/zip',
            default => null,
        };
        if ($contentType === null) {
            throw new RuntimeException("Вложение Trivio {$fileId} не является поддерживаемым PDF, изображением или ZIP.");
        }
        $extension = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'application/zip' => 'zip'][$contentType];
        $filename = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        $filename = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = "trivio-service-file-{$fileId}.{$extension}";
        } elseif (!in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), [$extension, $extension === 'jpg' ? 'jpeg' : $extension], true)) {
            $filename .= '.' . $extension;
        }
        return new TravelDocument($filename, $content, $contentType);
    }

    /** @return list<array<string, mixed>> */
    public function detailedOrders(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $this->orderErrors = [];
        $sessionId = $this->session();
        $timezone = new \DateTimeZone(self::API_TIMEZONE);
        $result = $this->call('getOrders', [
            'sessionId' => $sessionId,
            'searchParams' => [
                'order_date_from' => $from->setTimezone($timezone)->format('Y-m-d'),
                'order_date_to' => $to->setTimezone($timezone)->format('Y-m-d'),
            ],
        ]);
        $this->assertSuccessful($result, 'getOrders');

        $orders = $result['orders'] ?? [];
        if (!is_array($orders)) {
            return [];
        }
        if (isset($orders['order_id'])) {
            $orders = [$orders];
        }

        $currency = trim((string) ($result['currency_code'] ?? 'RUB')) ?: 'RUB';
        $detailedOrders = [];
        $seen = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = (int) ($order['order_id'] ?? 0);
            if ($orderId <= 0 || isset($seen[$orderId])) {
                continue;
            }
            $seen[$orderId] = true;
            try {
                $detailed = $this->detailedOrder($sessionId, $orderId);
            } catch (\Throwable $exception) {
                if (!$this->isRecoverableOrderError($exception)) {
                    throw $exception;
                }
                $this->orderErrors[$orderId] = $exception->getMessage();
                continue;
            }
            $payload = $detailed['orderDetailed'] ?? $detailed['order_detailed'];
            $payload['_currency'] = trim((string) ($detailed['currency_code'] ?? $currency)) ?: $currency;
            $detailedOrders[] = $payload;
        }

        return $detailedOrders;
    }

    /** @return array<int, string> */
    public function orderErrors(): array
    {
        return $this->orderErrors;
    }

    /** @return array<string, mixed> */
    private function detailedOrder(string $sessionId, int $orderId): array
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $detailed = $this->call('getOrder', [
                    'sessionId' => $sessionId,
                    'orderId' => $orderId,
                ]);
                $this->assertSuccessful($detailed, 'getOrder');
                $payload = $detailed['orderDetailed'] ?? $detailed['order_detailed'] ?? null;
                if (!is_array($payload)) {
                    throw new RuntimeException("Trivio Client API getOrder не вернул orderDetailed для заказа {$orderId}.");
                }

                return $detailed;
            } catch (\Throwable $exception) {
                $lastError = $exception;
                if (!$this->isRecoverableOrderError($exception) || $attempt >= 3) {
                    break;
                }
                usleep(400_000 * $attempt);
            }
        }

        throw new RuntimeException(
            "Заказ Trivio {$orderId}: " . ($lastError?->getMessage() ?? 'getOrder не вернул данные'),
            previous: $lastError,
        );
    }

    private function isRecoverableOrderError(\Throwable $exception): bool
    {
        $message = strtoupper($exception->getMessage());
        return str_contains($message, 'COMMON_ERROR')
            || str_contains($message, 'ОШИБКА СЕРВЕРА')
            || str_contains($message, 'ORDERDETAILED');
    }

    private function session(): string
    {
        if ($this->sessionId === null) {
            $this->authenticate();
        }

        return (string) $this->sessionId;
    }

    private function authenticate(): void
    {
        $login = trim($this->login);
        if ($login === '' || $this->password === '') {
            throw new RuntimeException('Для полной синхронизации Trivio укажите логин и пароль Client API.');
        }
        $result = $this->call('getSession', [
            'login' => $login,
            'password' => $this->password,
            'userAgent' => 'Bitrix24-Travel-Sync/1.0',
        ], self::ENDPOINT);
        $this->assertSuccessful($result, 'getSession');
        if (!empty($result['has_two_step_auth'])) {
            throw new RuntimeException('Для пользователя Trivio Client API включена двухфакторная авторизация. Создайте отдельного пользователя интеграции без 2FA.');
        }
        $session = is_array($result['session'] ?? null) ? $result['session'] : [];
        $sessionId = trim((string) ($session['session_id'] ?? ''));
        if ($sessionId === '') {
            throw new RuntimeException('Trivio Client API не вернул session_id.');
        }
        $this->sessionId = $sessionId;
        $this->endpoint = $this->endpointFromRouteHost((string) ($result['route_host'] ?? ''));
    }

    /** @return array<string, mixed> */
    private function call(string $method, array $params, ?string $endpoint = null): array
    {
        $payload = [
            'id' => $method . '-' . bin2hex(random_bytes(6)),
            'method' => $method,
            'params' => $params,
        ];
        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->http->request('POST', $endpoint ?? $this->endpoint, ['json' => $payload]);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
                if ($status === 429 || $status >= 500) {
                    throw new RuntimeException("HTTP {$status}", $status);
                }
                if ($status >= 400) {
                    throw new RuntimeException("HTTP {$status}", $status);
                }
                $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new RuntimeException('API вернул некорректный JSON.');
                }
                if (isset($decoded['error'])) {
                    $error = is_array($decoded['error']) ? $decoded['error'] : [];
                    throw new RuntimeException(trim((string) ($error['message'] ?? 'JSON-RPC error')), (int) ($error['code'] ?? 0));
                }
                $result = $decoded['result'] ?? null;
                if (!is_array($result)) {
                    throw new RuntimeException('В ответе отсутствует объект result.');
                }

                return $result;
            } catch (\Throwable $exception) {
                $lastError = $exception;
                $status = (int) $exception->getCode();
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    break;
                }
                if ($attempt < 3) {
                    usleep(200_000 * $attempt);
                }
            }
        }

        throw new RuntimeException(
            "Ошибка Trivio Client API {$method}: " . ($lastError?->getMessage() ?? 'сервер не ответил'),
            (int) ($lastError?->getCode() ?? 0),
            $lastError,
        );
    }

    private function assertSuccessful(array $result, string $method): void
    {
        $success = $result['result'] ?? false;
        if ($success !== true && $success !== 1 && $success !== '1' && strtolower((string) $success) !== 'true') {
            $message = html_entity_decode(
                trim((string) ($result['error'] ?? 'неизвестная ошибка')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );
            $code = trim((string) ($result['error_code'] ?? ''));
            throw new RuntimeException("Ошибка Trivio Client API {$method}: {$message}" . ($code !== '' ? " (код {$code})" : ''));
        }
    }

    private function endpointFromRouteHost(string $routeHost): string
    {
        $routeHost = trim($routeHost);
        if ($routeHost === '') {
            return self::ENDPOINT;
        }
        $candidate = str_contains($routeHost, '://') ? $routeHost : 'https://' . $routeHost;
        $parts = parse_url($candidate);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($scheme !== 'https' || $port !== 443 || ($host !== 'trivio.ru' && !str_ends_with($host, '.trivio.ru'))) {
            throw new RuntimeException('Trivio Client API передал небезопасный route_host.');
        }

        return 'https://' . $host . '/api/clientapi/json';
    }
}
