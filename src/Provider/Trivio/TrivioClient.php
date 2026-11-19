<?php

declare(strict_types=1);

namespace App\Provider\Trivio;

use DOMDocument;
use DOMElement;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TrivioClient
{
    private const ENDPOINT = 'https://corporate-api.trivio.ru/api/integration';
    private const NAMESPACE = 'https://corporate-api.trivio.ru/api/integration';

    private HttpClientInterface $http;

    public function __construct(
        private readonly string $token,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create([
            'timeout' => 30,
            'max_duration' => 60,
        ]);
    }

    public function testConnection(): void
    {
        $this->transactions();
    }

    /** @return list<array<string, mixed>> */
    public function transactions(): array
    {
        $result = $this->call('getTicketTransactionInfo', [
            'token' => $this->requiredToken(),
            'version' => '1',
        ]);
        $this->assertSuccessful($result, 'getTicketTransactionInfo');

        $transactions = $this->valueByKey($result, 'transactions');
        if (!is_array($transactions)) {
            return [];
        }
        $rows = $this->valueByKey($transactions, 'tickettransactiondto') ?? $transactions;
        if (!is_array($rows)) {
            return [];
        }
        if (isset($rows['transaction_id'])) {
            $rows = [$rows];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /** @return array{filename: string, content: string, contentType: string} */
    public function printBlank(int $serviceId, int $ticketId): array
    {
        if ($serviceId <= 0 || $ticketId <= 0) {
            throw new RuntimeException('Trivio не передал service_id или ticket_id для маршрутной квитанции.');
        }
        $result = $this->call('printBlank', [
            'token' => $this->requiredToken(),
            'serviceId' => $serviceId,
            'ticketId' => $ticketId,
        ]);
        $this->assertSuccessful($result, 'printBlank');

        $encoded = trim((string) ($this->valueByKey($result, 'data') ?? ''));
        $content = base64_decode($encoded, true);
        if ($encoded === '' || $content === false || $content === '') {
            throw new RuntimeException('Trivio вернул пустой или некорректный файл маршрутной квитанции.');
        }
        $extension = strtolower(trim((string) ($this->valueByKey($result, 'extension') ?? 'pdf')));
        $extension = ltrim($extension, '.');
        if (!in_array($extension, ['pdf', 'zip', 'png', 'jpg', 'jpeg'], true)) {
            $extension = 'pdf';
        }
        $contentType = match ($extension) {
            'zip' => 'application/zip',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/pdf',
        };

        return [
            'filename' => "trivio-ticket-{$ticketId}.{$extension}",
            'content' => $content,
            'contentType' => $contentType,
        ];
    }

    /** @return array<string, mixed> */
    private function call(string $method, array $params): array
    {
        $xml = '';
        foreach ($params as $name => $value) {
            $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $name) ?: 'param';
            $xml .= '<' . $safeName . '>'
                . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</' . $safeName . '>';
        }

        return $this->callXml($method, $xml);
    }

    /** @return array<string, mixed> */
    private function callXml(string $method, string $paramsXml): array
    {
        $body = $this->envelope($method, $paramsXml);
        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->http->request('POST', self::ENDPOINT, [
                    'headers' => [
                        'Accept' => 'text/xml, application/soap+xml',
                        'Content-Type' => 'text/xml; charset=utf-8',
                        'SOAPAction' => '"' . self::NAMESPACE . '#' . $method . '"',
                        'User-Agent' => 'Bitrix24-Travel-Sync/1.0',
                    ],
                    'body' => $body,
                ]);
                $status = $response->getStatusCode();
                $content = $response->getContent(false);
                if ($status >= 500 || $status === 429) {
                    throw new RuntimeException("HTTP {$status}", $status);
                }
                if ($status >= 400) {
                    throw new RuntimeException("HTTP {$status}: " . $this->soapError($content), $status);
                }

                return $this->parseResponse($content, $method);
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
            "Ошибка Trivio {$method}: " . ($lastError?->getMessage() ?? 'неизвестная ошибка'),
            (int) ($lastError?->getCode() ?? 0),
            $lastError,
        );
    }

    private function envelope(string $method, string $paramsXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:int="' . self::NAMESPACE . '">'
            . '<soapenv:Header/><soapenv:Body><int:' . $method . '>' . $paramsXml
            . '</int:' . $method . '></soapenv:Body></soapenv:Envelope>';
    }

    /** @return array<string, mixed> */
    private function parseResponse(string $xml, string $method): array
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('Для Trivio требуется PHP-расширение DOM.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new RuntimeException('Trivio вернул некорректный SOAP XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $faults = $document->getElementsByTagNameNS('*', 'Fault');
        if ($faults->length > 0 && $faults->item(0) instanceof DOMElement) {
            throw new RuntimeException('SOAP Fault: ' . trim($faults->item(0)->textContent));
        }
        $returns = $document->getElementsByTagName('return');
        if ($returns->length === 0) {
            $returns = $document->getElementsByTagNameNS('*', 'return');
        }
        $return = $returns->item(0);
        if (!$return instanceof DOMElement) {
            throw new RuntimeException("Trivio {$method} не вернул return.");
        }
        $value = $this->elementValue($return);

        return is_array($value) ? $value : [];
    }

    private function elementValue(DOMElement $element): mixed
    {
        if ($element->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'nil') === 'true') {
            return null;
        }
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        if ($children === []) {
            return trim($element->textContent);
        }

        $value = [];
        foreach ($children as $child) {
            $key = strtolower($child->localName ?: $child->nodeName);
            $item = $this->elementValue($child);
            if (!array_key_exists($key, $value)) {
                $value[$key] = $item;
            } elseif (is_array($value[$key]) && array_is_list($value[$key])) {
                $value[$key][] = $item;
            } else {
                $value[$key] = [$value[$key], $item];
            }
        }

        return $value;
    }

    private function assertSuccessful(array $result, string $method): void
    {
        $success = strtolower((string) ($this->valueByKey($result, 'result') ?? 'false'));
        if (!in_array($success, ['1', 'true'], true)) {
            $error = trim((string) ($this->valueByKey($result, 'error') ?? 'неизвестная ошибка'));
            throw new RuntimeException("Ошибка Trivio {$method}: {$error}");
        }
    }

    private function valueByKey(array $value, string $key): mixed
    {
        $needle = strtolower($key);
        foreach ($value as $itemKey => $item) {
            if (strtolower((string) $itemKey) === $needle) {
                return $item;
            }
        }
        return null;
    }

    private function soapError(string $xml): string
    {
        if (preg_match('/<(?:[^:>]+:)?faultstring[^>]*>(.*?)<\//is', $xml, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        return 'сервер не передал описание ошибки';
    }

    private function requiredToken(): string
    {
        $token = trim($this->token);
        if ($token === '') {
            throw new RuntimeException('Не указан токен Trivio Integration API.');
        }
        return $token;
    }

}
