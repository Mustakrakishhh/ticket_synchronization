<?php

declare(strict_types=1);

use App\Application;
use App\Bitrix24\CrmClientInterface;
use App\Domain\ServiceType;
use App\Domain\TravelDocument;
use App\Domain\TravelDocumentUnavailableException;
use App\Domain\TravelItem;
use App\Provider\ProviderFactoryInterface;
use App\Provider\TravelDocumentProviderInterface;
use App\Provider\TravelProviderInterface;
use App\Sync\FieldMapper;
use App\Sync\SyncService;

$defaults = [
    'APP_ENV' => 'test',
    'APP_PUBLIC_URL' => 'https://example.test',
    'APP_SESSION_SECRET' => 'test-session-secret-with-at-least-32-characters',
    'APP_ENCRYPTION_KEY' => 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
    'BITRIX24_CLIENT_ID' => 'test-client',
    'BITRIX24_CLIENT_SECRET' => 'test-secret',
    'DATABASE_DSN' => 'sqlite::memory:',
];
foreach ($defaults as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
}

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';
$member = 'sync-test-member';
$app->repository->saveProvider($member, 'trivio', ['token' => 'x'], true);
$app->repository->saveSettings($member, [
    'entityTypeId' => 177,
    'enabled' => false,
    'createItems' => true,
    'updateItems' => true,
    'preserveTitleOnUpdate' => true,
    'attachDocuments' => true,
    'assignedById' => 42,
    'titleTemplate' => '{service_type_label}: {ticket_number}',
    'serviceRoutes' => [
        ServiceType::AIR => ['enabled' => true, 'categoryId' => 7, 'stageId' => 'DT177_7:NEW'],
    ],
]);
$app->repository->saveMappings($member, 'trivio', [
    ['sourceField' => 'external_id', 'targetField' => 'ufCrmExternal', 'transform' => 'string'],
    ['sourceField' => 'amount', 'targetField' => 'opportunity', 'transform' => 'money'],
    ['sourceField' => 'ticket_file', 'targetField' => 'ufCrmTicketFile', 'transform' => 'file'],
]);

$provider = new class implements TravelProviderInterface, TravelDocumentProviderInterface {
    public TravelItem $item;
    public int $documentCalls = 0;
    public bool $failDocument = false;
    public bool $unavailableDocument = false;
    public array $sourceErrors = [];

    public function __construct()
    {
        $this->item = new TravelItem('trivio', 'air:1', ServiceType::AIR, [
            'ticket_number' => '555-1',
            'amount' => 1000,
            'currency' => 'RUB',
            'updated_at' => '2026-01-01T10:00:00Z',
        ]);
    }

    public function code(): string { return 'trivio'; }
    public function testConnection(): void {}
    public function fetch(?string $cursor, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'items' => [$this->item],
            'nextCursor' => $to->format(DATE_ATOM),
            'sourceErrors' => $this->sourceErrors,
            'historyOrderErrors' => count($this->sourceErrors),
        ];
    }
    public function ticketDocument(TravelItem $item): ?TravelDocument
    {
        $this->documentCalls++;
        if ($this->failDocument) {
            throw new RuntimeException('Document endpoint unavailable');
        }
        if ($this->unavailableDocument) {
            throw new TravelDocumentUnavailableException('Document is not issued yet');
        }
        return new TravelDocument('ticket.pdf', "%PDF-1.4\nmock", 'application/pdf');
    }
};

$factory = new class($provider) implements ProviderFactoryInterface {
    public function __construct(private TravelProviderInterface $provider) {}
    public function create(string $provider, array $credentials): TravelProviderInterface { return $this->provider; }
};

$crm = new class implements CrmClientInterface {
    public int $adds = 0;
    public int $updates = 0;
    public array $lastFields = [];
    public array $addFields = [];
    public array $updateFields = [];
    public bool $exists = true;

    public function itemExists(int $entityTypeId, int $itemId): bool
    {
        return $this->exists;
    }

    public function findItemIdByField(int $entityTypeId, string $field, mixed $value): ?int
    {
        return null;
    }

    public function addItem(int $entityTypeId, array $fields): int
    {
        $this->adds++;
        $this->lastFields = $fields;
        $this->addFields = $fields;
        return 900;
    }

    public function updateItem(int $entityTypeId, int $itemId, array $fields): void
    {
        $this->updates++;
        $this->lastFields = $fields;
        $this->updateFields = $fields;
    }
};

$service = new SyncService($app->repository, $factory, new FieldMapper());
$first = $service->run($member, 'trivio', $crm, 'manual');
$second = $service->run($member, 'trivio', $crm, 'manual');
$provider->item = new TravelItem('trivio', 'air:1', ServiceType::AIR, [
    'ticket_number' => '555-1',
    'amount' => 1200,
    'currency' => 'RUB',
    'updated_at' => '2026-01-01T11:00:00Z',
]);
$third = $service->run($member, 'trivio', $crm, 'manual');
$crm->exists = false;
$fourth = $service->run($member, 'trivio', $crm, 'rescan');

if ($first['stats']['created'] !== 1
    || $second['stats']['unchanged'] !== 1
    || $third['stats']['updated'] !== 1
    || $fourth['stats']['created'] !== 1
    || $crm->adds !== 2
    || $crm->updates !== 1
    || $provider->documentCalls !== 3
    || $first['stats']['documents'] !== 1
    || $second['stats']['documents'] !== 0
    || $third['stats']['documents'] !== 0
    || ($crm->addFields['ufCrmTicketFile'][0] ?? null) !== 'ticket.pdf'
    || base64_decode((string) ($crm->addFields['ufCrmTicketFile'][1] ?? ''), true) !== "%PDF-1.4\nmock"
    || array_key_exists('ufCrmTicketFile', $crm->updateFields)
    || ($crm->addFields['title'] ?? null) !== 'Авиабилеты: 555-1'
    || array_key_exists('title', $crm->updateFields)
    || ($crm->lastFields['categoryId'] ?? null) !== 7
    || ($crm->lastFields['stageId'] ?? null) !== 'DT177_7:NEW'
    || ($crm->lastFields['assignedById'] ?? null) !== 42
    || array_key_exists('assignedById', $crm->updateFields)
) {
    throw new RuntimeException('Idempotent sync test failed: ' . json_encode([
        'first' => $first,
        'second' => $second,
        'third' => $third,
        'fourth' => $fourth,
        'adds' => $crm->adds,
        'updates' => $crm->updates,
        'documentCalls' => $provider->documentCalls,
        'lastFields' => $crm->lastFields,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

$app->repository->saveMappings($member, 'trivio', [
    ['sourceField' => 'external_id', 'targetField' => 'ufCrmExternal', 'transform' => 'string'],
    ['sourceField' => 'amount', 'targetField' => 'opportunity', 'transform' => 'money'],
    ['sourceField' => 'ticket_file', 'targetField' => 'ufCrmTicketFile', 'transform' => 'file_multiple'],
]);
$multipleFileResult = $service->run($member, 'trivio', $crm, 'manual');
if ($multipleFileResult['stats']['updated'] !== 1
    || $multipleFileResult['stats']['documents'] !== 1
    || ($crm->updateFields['ufCrmTicketFile'][0][0] ?? null) !== 'ticket.pdf'
    || base64_decode((string) ($crm->updateFields['ufCrmTicketFile'][0][1] ?? ''), true) !== "%PDF-1.4\nmock"
) {
    throw new RuntimeException('Multiple file field upload format is invalid.');
}

$provider->failDocument = true;
$provider->item = new TravelItem('trivio', 'air:2', ServiceType::AIR, [
    'ticket_number' => '555-2',
    'amount' => 900,
    'currency' => 'RUB',
    'updated_at' => '2026-01-01T12:00:00Z',
]);
$crm->exists = true;
$withDocumentError = $service->run($member, 'trivio', $crm, 'manual');
if ($withDocumentError['state'] !== 'partial'
    || $withDocumentError['stats']['created'] !== 1
    || $withDocumentError['stats']['documentErrors'] !== 1
    || $crm->adds !== 3
) {
    throw new RuntimeException('Document error must not block CRM item creation.');
}

$provider->failDocument = false;
$provider->item = new TravelItem('trivio', 'air:3', ServiceType::AIR, [
    'ticket_number' => '555-3',
    'amount' => 700,
    'currency' => 'RUB',
    'updated_at' => '2026-01-01T13:00:00Z',
]);
$queuedRunId = $app->repository->enqueueSyncRun($member, 'trivio', 'manual');
if (!$app->repository->claimSyncRun($queuedRunId)) {
    throw new RuntimeException('Queued sync could not be claimed.');
}
$queuedResult = $service->runClaimed($queuedRunId, $member, 'trivio', $crm);
if ($queuedResult['state'] !== 'success'
    || $queuedResult['stats']['created'] !== 1
    || ($app->repository->syncRun($queuedRunId)['state'] ?? null) !== 'success'
) {
    throw new RuntimeException('Queued sync execution failed.');
}

$addsBeforeProcessSwitch = $crm->adds;
$app->repository->saveSettings($member, [
    'entityTypeId' => 178,
    'enabled' => false,
    'createItems' => true,
    'updateItems' => true,
    'attachDocuments' => false,
    'titleTemplate' => '{service_type_label}: {ticket_number}',
    'serviceRoutes' => [
        ServiceType::AIR => ['enabled' => true, 'categoryId' => 7, 'stageId' => 'DT178_7:NEW'],
    ],
]);
$processSwitchResult = $service->run($member, 'trivio', $crm, 'manual');
if ($processSwitchResult['stats']['created'] !== 1 || $crm->adds !== $addsBeforeProcessSwitch + 1) {
    throw new RuntimeException('The same external item must be created separately in another smart process.');
}

$accommodationMember = 'sync-accommodation-member';
$app->repository->saveProvider($accommodationMember, 'trivio', ['token' => 'x'], true);
$app->repository->saveSettings($accommodationMember, [
    'entityTypeId' => 177,
    'enabled' => false,
    'createItems' => true,
    'updateItems' => true,
    'attachDocuments' => false,
    'titleTemplate' => 'Билет: {ticket_number}',
    'accommodationTitleTemplate' => 'Хостел: {hotel_name} — {passenger}, {check_in}–{check_out}',
    'serviceRoutes' => [
        ServiceType::ACCOMMODATION => ['enabled' => true, 'categoryId' => 8, 'stageId' => 'DT177_8:NEW'],
    ],
]);
$app->repository->saveMappings($accommodationMember, 'trivio', [
    ['sourceField' => 'external_id', 'targetField' => 'ufCrmExternal', 'transform' => 'string'],
]);
$provider->item = new TravelItem('trivio', 'accommodation:1', ServiceType::ACCOMMODATION, [
    'hotel_name' => 'Тестовый хостел',
    'passenger' => 'Иван Иванов',
    'check_in' => '2026-01-02',
    'check_out' => '2026-01-04',
    'updated_at' => '2026-01-01T14:00:00Z',
]);
$accommodationResult = $service->run($accommodationMember, 'trivio', $crm, 'manual');
if ($accommodationResult['stats']['created'] !== 1
    || ($crm->lastFields['title'] ?? null) !== 'Хостел: Тестовый хостел — Иван Иванов, 2026-01-02–2026-01-04'
) {
    throw new RuntimeException('Accommodation title template was not applied.');
}

$unavailableMember = 'sync-unavailable-document-member';
$app->repository->saveProvider($unavailableMember, 'trivio', ['token' => 'x'], true);
$app->repository->saveSettings($unavailableMember, [
    'entityTypeId' => 177,
    'enabled' => false,
    'createItems' => true,
    'updateItems' => true,
    'attachDocuments' => true,
    'serviceRoutes' => [
        ServiceType::AIR => ['enabled' => true, 'categoryId' => 7, 'stageId' => 'DT177_7:NEW'],
    ],
]);
$app->repository->saveMappings($unavailableMember, 'trivio', [
    ['sourceField' => 'external_id', 'targetField' => 'ufCrmExternal', 'transform' => 'string'],
    ['sourceField' => 'ticket_file', 'targetField' => 'ufCrmTicketFile', 'transform' => 'file'],
]);
$provider->failDocument = false;
$provider->unavailableDocument = true;
$provider->item = new TravelItem('trivio', 'air:missing-document', ServiceType::AIR, [
    'ticket_number' => 'MISSING-1',
    'updated_at' => '2026-01-01T15:00:00Z',
]);
$documentCallsBefore = $provider->documentCalls;
$unavailableFirst = $service->run($unavailableMember, 'trivio', $crm, 'manual');
$unavailableSecond = $service->run($unavailableMember, 'trivio', $crm, 'manual');
if ($unavailableFirst['state'] !== 'success'
    || $unavailableFirst['stats']['created'] !== 1
    || $unavailableFirst['stats']['errors'] !== 0
    || $unavailableFirst['stats']['documentsUnavailable'] !== 1
    || $unavailableSecond['stats']['unchanged'] !== 1
    || $unavailableSecond['stats']['documentsUnavailable'] !== 1
    || $provider->documentCalls !== $documentCallsBefore + 2
) {
    throw new RuntimeException('Unavailable document must remain non-fatal and retry on every sync run: ' . json_encode([
        'first' => $unavailableFirst,
        'second' => $unavailableSecond,
        'firstRun' => $app->repository->syncRun($unavailableFirst['runId']),
        'secondRun' => $app->repository->syncRun($unavailableSecond['runId']),
        'documentCallsBefore' => $documentCallsBefore,
        'documentCallsAfter' => $provider->documentCalls,
        'adds' => $crm->adds,
        'updates' => $crm->updates,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

$provider->unavailableDocument = false;
$provider->item = new TravelItem('trivio', 'air:without-ticket-number', ServiceType::AIR, [
    'ticket_number' => null,
    'updated_at' => '2026-01-01T16:00:00Z',
]);
$addsBeforeMissingTicket = $crm->adds;
$documentCallsBeforeMissingTicket = $provider->documentCalls;
$missingTicketResult = $service->run($unavailableMember, 'trivio', $crm, 'manual');
if ($missingTicketResult['state'] !== 'success'
    || $missingTicketResult['stats']['skipped'] !== 1
    || $missingTicketResult['stats']['skippedMissingTicketNumber'] !== 1
    || $crm->adds !== $addsBeforeMissingTicket
    || $provider->documentCalls !== $documentCallsBeforeMissingTicket
) {
    throw new RuntimeException('Transport ticket without a ticket number must be skipped before document and CRM calls.');
}

$provider->sourceErrors = ['Заказ Trivio 1001: COMMON_ERROR'];
$provider->item = new TravelItem('trivio', 'air:partial-history', ServiceType::AIR, [
    'ticket_number' => 'PARTIAL-1',
    'updated_at' => '2026-01-01T17:00:00Z',
]);
$partialHistoryResult = $service->run($unavailableMember, 'trivio', $crm, 'manual');
if ($partialHistoryResult['state'] !== 'partial'
    || $partialHistoryResult['stats']['created'] !== 1
    || $partialHistoryResult['stats']['errors'] !== 1
    || $partialHistoryResult['stats']['historyOrderErrors'] !== 1
) {
    throw new RuntimeException('A failed Trivio order must not block synchronization of successful orders.');
}

$aviasalesMember = 'sync-aviasales-without-ticket-number-member';
$app->repository->saveProvider($aviasalesMember, 'aviasales', ['api_key' => 'x', 'company_id' => 'company'], true);
$app->repository->saveSettings($aviasalesMember, [
    'entityTypeId' => 177,
    'enabled' => false,
    'createItems' => true,
    'updateItems' => true,
    'attachDocuments' => false,
    'titleTemplate' => '{provider}: билет {ticket_number} — {passenger}',
    'serviceRoutes' => [
        ServiceType::RAIL => ['enabled' => true, 'categoryId' => 7, 'stageId' => 'DT177_7:NEW'],
    ],
]);
$app->repository->saveMappings($aviasalesMember, 'aviasales', [
    ['sourceField' => 'external_id', 'targetField' => 'ufCrmExternal', 'transform' => 'string'],
]);
$provider->sourceErrors = [];
$provider->item = new TravelItem('aviasales', 'rail:fbd7a0c0-1111-2222-3333-444444444444', ServiceType::RAIL, [
    'order_id' => 'fbd7a0c0-1111-2222-3333-444444444444',
    'short_order_id' => 'FBD7A0C0',
    'ticket_number' => null,
    'passenger' => 'Иван Иванов',
    'updated_at' => '2026-01-01T18:00:00Z',
]);
$aviasalesResult = $service->run($aviasalesMember, 'aviasales', $crm, 'manual');
if ($aviasalesResult['state'] !== 'success'
    || $aviasalesResult['stats']['created'] !== 1
    || $aviasalesResult['stats']['skippedMissingTicketNumber'] !== 0
    || ($crm->lastFields['title'] ?? null) !== 'aviasales: билет FBD7A0C0 — Иван Иванов'
) {
    throw new RuntimeException('Aviasales ticket without a ticket number must be synchronized using the short order ID in its title.');
}

echo json_encode([
    'created' => $crm->adds,
    'updated' => $crm->updates,
    'unchanged' => $second['stats']['unchanged'],
    'categoryId' => $crm->lastFields['categoryId'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
