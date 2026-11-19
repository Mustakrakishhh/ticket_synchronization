<?php

declare(strict_types=1);

use App\Application;
use App\Bitrix24\CrmClientInterface;
use App\Domain\ServiceType;
use App\Domain\TravelItem;
use App\Provider\ProviderFactoryInterface;
use App\Provider\TravelProviderInterface;
use App\Sync\ExpenseCatalog;
use App\Sync\FieldMapper;
use App\Sync\SyncService;

foreach ([
    'APP_ENV' => 'test',
    'APP_PUBLIC_URL' => 'https://example.test',
    'APP_SESSION_SECRET' => 'test-session-secret-with-at-least-32-characters',
    'APP_ENCRYPTION_KEY' => 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
    'BITRIX24_CLIENT_ID' => 'test-client',
    'BITRIX24_CLIENT_SECRET' => 'test-secret',
    'DATABASE_DSN' => 'sqlite::memory:',
] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
}

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';
$member = 'expense-sync-member';
$app->repository->saveProvider($member, 'trivio', ['token' => 'test'], true);
$app->repository->saveSettings($member, [
    'entityTypeId' => 177,
    'enabled' => false,
    'createItems' => true,
    'updateItems' => true,
    'attachDocuments' => false,
    'serviceRoutes' => [
        ServiceType::AIR => ['enabled' => true, 'categoryId' => 7, 'stageId' => 'DT177_7:NEW'],
    ],
    'expenseSync' => [
        'enabled' => true,
        'entityTypeId' => 299,
        'categoryId' => 9,
        'stageId' => 'DT299_9:NEW',
        'assignedById' => 42,
        'titleTemplate' => 'Затрата {ticket_number} — {passenger}',
        'accommodationTitleTemplate' => 'Проживание {hotel_name} — {passenger}',
        'createItems' => true,
        'updateItems' => true,
        'preserveTitleOnUpdate' => true,
    ],
]);
$app->repository->saveMappings($member, 'trivio', [
    ['sourceField' => 'external_id', 'targetField' => 'ufTicketExternal', 'transform' => 'string'],
]);
$app->repository->saveMappings($member, ExpenseCatalog::MAPPING_SCOPE, [
    ['sourceField' => 'expense_external_id', 'targetField' => 'ufExpenseExternal', 'transform' => 'string'],
    ['sourceField' => 'ticket_number', 'targetField' => 'ufTicketNumber', 'transform' => 'string'],
    ['sourceField' => 'service_ids', 'targetField' => 'ufServiceIds', 'transform' => 'string'],
    ['sourceField' => 'amount', 'targetField' => 'opportunity', 'transform' => 'money'],
    ['sourceField' => 'original_amount', 'targetField' => 'ufOriginalAmount', 'transform' => 'money'],
    ['sourceField' => 'refund_amount', 'targetField' => 'ufRefundAmount', 'transform' => 'money'],
    ['sourceField' => 'penalty_amount', 'targetField' => 'ufPenaltyAmount', 'transform' => 'money'],
    ['sourceField' => 'net_amount', 'targetField' => 'ufNetAmount', 'transform' => 'money'],
    ['sourceField' => 'expense_status_label', 'targetField' => 'ufExpenseStatus', 'transform' => 'string'],
    ['sourceField' => 'calculation_status_label', 'targetField' => 'ufCalculationStatus', 'transform' => 'string'],
    ['sourceField' => 'passenger', 'targetField' => 'ufPassenger', 'transform' => 'string'],
    ['sourceField' => 'supplier', 'targetField' => 'ufSupplier', 'transform' => 'string'],
    ['sourceField' => 'service_url', 'targetField' => 'ufServiceUrl', 'transform' => 'string'],
    ['sourceField' => 'hotel_name', 'targetField' => 'ufHotelName', 'transform' => 'string'],
    ['sourceField' => 'check_in', 'targetField' => 'ufCheckIn', 'transform' => 'date'],
    ['sourceField' => 'check_out', 'targetField' => 'ufCheckOut', 'transform' => 'date'],
]);

$provider = new class implements TravelProviderInterface {
    public float $baggageAmount = 200.0;
    public bool $returned = false;

    public function code(): string { return 'trivio'; }
    public function testConnection(): void {}
    public function fetch(?string $cursor, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return ['items' => [
            new TravelItem('trivio', 'air:ticket-1', ServiceType::AIR, [
                'order_id' => 'order-1',
                'service_id' => 'svc-ticket',
                'ticket_number' => '9990000000002',
                'passenger' => 'Тестов Иван',
                'amount' => 1000,
                'status' => $this->returned ? 'Возврат' : 'Покупка',
                'status_code' => $this->returned ? '5' : '2',
                'expense_status' => $this->returned ? 'refunded' : 'active',
                'refund_amount' => $this->returned ? 700 : null,
                'penalty_amount' => $this->returned ? 300 : null,
                'original_amount' => $this->returned ? 1000 : null,
                'net_amount' => $this->returned ? 300 : null,
                'calculation_status' => $this->returned ? 'provider_exact' : 'source_amount',
                'currency' => 'RUB',
                'service_url' => 'https://corporate.trivio.ru/order/show?order_id=2858697#data',
                'updated_at' => '2026-08-27T10:00:00Z',
            ]),
            new TravelItem('trivio', 'air:baggage-1', ServiceType::ADDITIONAL_SERVICE, [
                'order_id' => 'order-1',
                'service_id' => 'svc-bag',
                'ticket_number' => '9990000000002',
                'passenger' => 'Тестов Иван',
                'route' => 'Багаж → Дополнительная услуга',
                'amount' => $this->baggageAmount,
                'currency' => 'RUB',
                'updated_at' => '2026-08-27T10:05:00Z',
            ]),
            new TravelItem('trivio', 'air:seat-1', ServiceType::ADDITIONAL_SERVICE, [
                'order_id' => 'order-1',
                'service_id' => 'svc-seat',
                'ticket_number' => '9990000000002',
                'passenger' => 'Тестов Иван',
                'route' => 'Выбор места → Дополнительная услуга',
                'amount' => 150,
                'currency' => 'RUB',
                'updated_at' => '2026-08-27T10:06:00Z',
            ]),
            new TravelItem('trivio', 'accommodation:hotel-1', ServiceType::ACCOMMODATION, [
                'order_id' => 'order-2',
                'service_id' => 'svc-hotel',
                'passenger' => 'Тестов Иван',
                'hotel_name' => 'Тестовый хостел',
                'hotel_address' => 'Москва, Тестовая улица, 1',
                'room_name' => 'Стандарт',
                'check_in' => '2026-08-28T14:00:00+03:00',
                'check_out' => '2026-08-30T12:00:00+03:00',
                'amount' => 2500,
                'currency' => 'RUB',
                'service_url' => 'https://corporate.trivio.ru/order/show?order_id=2858700#data',
                'updated_at' => '2026-08-27T10:07:00Z',
            ]),
        ], 'nextCursor' => $to->format(DATE_ATOM)];
    }
};
$factory = new class($provider) implements ProviderFactoryInterface {
    public function __construct(private TravelProviderInterface $provider) {}
    public function create(string $provider, array $credentials): TravelProviderInterface { return $this->provider; }
};
$crm = new class implements CrmClientInterface {
    public int $nextId = 100;
    public array $adds = [];
    public array $updates = [];
    public function itemExists(int $entityTypeId, int $itemId): bool { return true; }
    public function findItemIdByField(int $entityTypeId, string $field, mixed $value): ?int { return null; }
    public function addItem(int $entityTypeId, array $fields): int
    {
        $id = $this->nextId++;
        $this->adds[] = compact('entityTypeId', 'fields', 'id');
        return $id;
    }
    public function updateItem(int $entityTypeId, int $itemId, array $fields): void
    {
        $this->updates[] = compact('entityTypeId', 'itemId', 'fields');
    }
};

$service = new SyncService($app->repository, $factory, new FieldMapper());
$first = $service->run($member, 'trivio', $crm, 'manual');
$second = $service->run($member, 'trivio', $crm, 'manual');
$provider->baggageAmount = 250.0;
$third = $service->run($member, 'trivio', $crm, 'manual');
$provider->returned = true;
$fourth = $service->run($member, 'trivio', $crm, 'manual');

$expenseAdd = current(array_filter($crm->adds, static fn (array $row): bool => $row['entityTypeId'] === 299));
$hotelExpenseAdd = current(array_filter($crm->adds, static fn (array $row): bool => $row['entityTypeId'] === 299
    && ($row['fields']['ufHotelName'] ?? null) === 'Тестовый хостел'));
$expenseUpdate = current(array_filter($crm->updates, static fn (array $row): bool => $row['entityTypeId'] === 299));
$refundUpdate = current(array_filter($crm->updates, static fn (array $row): bool => $row['entityTypeId'] === 299
    && ($row['fields']['opportunity'] ?? null) === '700.00|RUB'));
if ($first['stats']['expenseReceived'] !== 2
    || $first['stats']['expenseCreated'] !== 2
    || $second['stats']['expenseUnchanged'] !== 2
    || $third['stats']['expenseUpdated'] !== 1
    || $fourth['stats']['expenseUpdated'] !== 1
    || ($expenseAdd['fields']['ufExpenseExternal'] ?? null) !== 'expense:trivio:air:ticket-1'
    || ($expenseAdd['fields']['opportunity'] ?? null) !== '1350.00|RUB'
    || ($expenseAdd['fields']['ufServiceIds'] ?? null) !== 'svc-ticket, svc-bag, svc-seat'
    || ($expenseAdd['fields']['ufSupplier'] ?? null) !== 'Trivio'
    || ($expenseAdd['fields']['ufServiceUrl'] ?? null) !== 'https://corporate.trivio.ru/order/show?order_id=2858697#data'
    || ($expenseAdd['fields']['categoryId'] ?? null) !== 9
    || ($expenseAdd['fields']['stageId'] ?? null) !== 'DT299_9:NEW'
    || ($expenseAdd['fields']['assignedById'] ?? null) !== 42
    || ($hotelExpenseAdd['fields']['opportunity'] ?? null) !== '2500.00|RUB'
    || ($hotelExpenseAdd['fields']['title'] ?? null) !== 'Проживание Тестовый хостел — Тестов Иван'
    || ($hotelExpenseAdd['fields']['ufCheckIn'] ?? null) !== '2026-08-28T14:00:00+03:00'
    || ($hotelExpenseAdd['fields']['ufCheckOut'] ?? null) !== '2026-08-30T12:00:00+03:00'
    || ($expenseUpdate['fields']['opportunity'] ?? null) !== '1400.00|RUB'
    || array_key_exists('title', $expenseUpdate['fields'] ?? [])
    || array_key_exists('assignedById', $expenseUpdate['fields'] ?? [])
    || ($refundUpdate['fields']['ufOriginalAmount'] ?? null) !== '1400.00|RUB'
    || ($refundUpdate['fields']['ufRefundAmount'] ?? null) !== '700.00|RUB'
    || ($refundUpdate['fields']['ufPenaltyAmount'] ?? null) !== '300.00|RUB'
    || ($refundUpdate['fields']['ufNetAmount'] ?? null) !== '700.00|RUB'
    || ($refundUpdate['fields']['ufExpenseStatus'] ?? null) !== 'Возврат'
    || ($refundUpdate['fields']['ufCalculationStatus'] ?? null) !== 'Точный расчёт по данным провайдера'
) {
    throw new RuntimeException('Expense aggregation or idempotent synchronization failed: ' . json_encode([
        'first' => $first,
        'second' => $second,
        'third' => $third,
        'fourth' => $fourth,
        'expenseAdd' => $expenseAdd,
        'hotelExpenseAdd' => $hotelExpenseAdd,
        'expenseUpdate' => $expenseUpdate,
        'refundUpdate' => $refundUpdate,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

echo "Expense synchronization checks passed.\n";
