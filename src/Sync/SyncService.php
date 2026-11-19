<?php

declare(strict_types=1);

namespace App\Sync;

use App\AppRepository;
use App\Bitrix24\CrmClientInterface;
use App\Domain\ServiceType;
use App\Domain\TravelDocumentUnavailableException;
use App\Provider\ProviderCatalog;
use App\Provider\ProviderFactoryInterface;
use App\Provider\ProcessingFeedbackProviderInterface;
use App\Provider\TravelDocumentProviderInterface;
use RuntimeException;

final readonly class SyncService
{
    private ExpenseAggregator $expenseAggregator;

    public function __construct(
        private AppRepository $repository,
        private ProviderFactoryInterface $providers,
        private FieldMapper $mapper,
        ?ExpenseAggregator $expenseAggregator = null,
    ) {
        $this->expenseAggregator = $expenseAggregator ?? new ExpenseAggregator();
    }

    public function run(string $memberId, string $providerCode, CrmClientInterface $bitrix, string $trigger = 'manual'): array
    {
        $runId = $this->repository->startSyncRun($memberId, $providerCode, $trigger);
        return $this->execute($runId, $memberId, $providerCode, $bitrix, $trigger);
    }

    public function runClaimed(
        string $runId,
        string $memberId,
        string $providerCode,
        CrmClientInterface $bitrix,
    ): array {
        $run = $this->repository->syncRun($runId);
        if ($run === null
            || $run['state'] !== 'running'
            || !hash_equals((string) $run['member_id'], $memberId)
            || !hash_equals((string) $run['provider'], $providerCode)
        ) {
            throw new RuntimeException('Задача синхронизации не захвачена worker-ом или уже завершена.');
        }
        $trigger = (string) ($run['stats']['trigger'] ?? 'manual');
        return $this->execute($runId, $memberId, $providerCode, $bitrix, $trigger);
    }

    private function execute(
        string $runId,
        string $memberId,
        string $providerCode,
        CrmClientInterface $bitrix,
        string $trigger,
    ): array {
        $run = $this->repository->syncRun($runId);
        $stats = array_merge(is_array($run['stats'] ?? null) ? $run['stats'] : [], [
            'trigger' => $trigger,
            'received' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'skippedMissingTicketNumber' => 0,
            'errors' => 0,
            'documents' => 0,
            'documentErrors' => 0,
            'documentsUnavailable' => 0,
            'recoveredCreates' => 0,
            'expenseReceived' => 0,
            'expenseCreated' => 0,
            'expenseUpdated' => 0,
            'expenseUnchanged' => 0,
            'expenseSkipped' => 0,
            'expenseErrors' => 0,
            'total' => 0,
        ]);
        $itemErrors = [];
        $feedbackErrors = [];
        $lastHeartbeat = 0.0;
        $heartbeat = function (bool $force = false) use ($runId, &$stats, &$lastHeartbeat): void {
            $now = microtime(true);
            if (!$force && $stats['received'] % 10 !== 0 && $now - $lastHeartbeat < 5) {
                return;
            }
            $stats['heartbeatAt'] = gmdate(DATE_ATOM);
            $this->repository->heartbeatSyncRun($runId, $stats);
            $lastHeartbeat = $now;
        };

        try {
            $heartbeat(true);
            $settings = $this->repository->settings($memberId);
            $entityTypeId = (int) ($settings['entityTypeId'] ?? 0);
            if ($entityTypeId <= 0) {
                throw new RuntimeException('Не выбран смарт-процесс Bitrix24.');
            }
            $credentials = $this->repository->providerCredentials($memberId, $providerCode);
            $provider = $this->providers->create($providerCode, $credentials);
            $cursor = $this->repository->syncCursor($memberId, $providerCode);
            $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $lookbackDays = max(1, min(30, (int) ($settings['initialLookbackDays'] ?? 7)));
            $useCursor = !in_array($trigger, ['refresh', 'rescan', 'schedule'], true) && $cursor !== null;
            $from = $useCursor && !ctype_digit($cursor)
                ? (new \DateTimeImmutable($cursor))->modify('-2 hours')
                : $to->modify("-{$lookbackDays} days");
            $stats['sourceMode'] = $providerCode === 'trivio' ? 'provider_queue' : 'date_range';
            $stats['requestedFrom'] = $from->format(DATE_ATOM);
            $stats['requestedTo'] = $to->format(DATE_ATOM);
            $batch = $provider->fetch($useCursor ? $cursor : null, $from, $to);
            $stats['sourceMode'] = (string) ($batch['sourceMode'] ?? $stats['sourceMode']);
            if (isset($batch['queueTransactions'])) {
                $stats['queueTransactions'] = (int) $batch['queueTransactions'];
            }
            if (isset($batch['historyOrders'])) {
                $stats['historyOrders'] = (int) $batch['historyOrders'];
            }
            if (isset($batch['historyOrderErrors'])) {
                $stats['historyOrderErrors'] = (int) $batch['historyOrderErrors'];
            }
            foreach (is_array($batch['sourceErrors'] ?? null) ? $batch['sourceErrors'] : [] as $sourceError) {
                $sourceError = trim((string) $sourceError);
                if ($sourceError !== '') {
                    $stats['errors']++;
                    $itemErrors[] = $sourceError;
                }
            }
            $stats['total'] = count($batch['items']);
            $heartbeat(true);
            $mappings = $this->repository->mappings($memberId, $providerCode);
            $identitySourceField = (string) ProviderCatalog::get($providerCode)['identityField'];
            $identityTargetField = null;
            foreach ($mappings as $mapping) {
                if (($mapping['sourceField'] ?? null) === $identitySourceField
                    && trim((string) ($mapping['targetField'] ?? '')) !== ''
                ) {
                    $identityTargetField = (string) $mapping['targetField'];
                    break;
                }
            }
            $routes = is_array($settings['serviceRoutes'] ?? null) ? $settings['serviceRoutes'] : [];
            $additionalServices = new TicketAdditionalServices();
            $associations = $additionalServices->associations($providerCode, $batch['items']);

            foreach ($batch['items'] as $item) {
                $stats['received']++;
                $heartbeat();
                $source = $item->fields();
                if (empty($settings['syncReturns']) && $this->isReturnOrCancellation($source)) {
                    $stats['skipped']++;
                    continue;
                }
                if ($providerCode !== 'aviasales'
                    && ServiceType::requiresTicketNumber($item->serviceType)
                    && trim((string) ($source['ticket_number'] ?? '')) === ''
                ) {
                    $stats['skipped']++;
                    $stats['skippedMissingTicketNumber']++;
                    continue;
                }
                $route = $routes[$item->serviceType] ?? $routes[ServiceType::OTHER] ?? null;
                if (!is_array($route) || empty($route['enabled']) || !isset($route['categoryId'])) {
                    $stats['skipped']++;
                    continue;
                }
                $externalId = $item->externalId;
                $existing = $this->repository->syncItem($memberId, $providerCode, $entityTypeId, $externalId);
                if ($trigger === 'rescan'
                    && !empty($existing['bitrix_item_id'])
                    && !$bitrix->itemExists($entityTypeId, (int) $existing['bitrix_item_id'])
                ) {
                    $existing = null;
                }
                $previousPayload = json_decode((string) ($existing['payload_json'] ?? ''), true);
                $previousPayload = is_array($previousPayload) ? $previousPayload : [];
                $item = $additionalServices->enrich(
                    $item,
                    $associations[$externalId] ?? [],
                    $previousPayload,
                    in_array((string) ($source['order_id'] ?? ''), $batch['completeOrderIds'] ?? [], true),
                );
                $source = $item->fields();
                $fields = $this->mapper->map($source, $mappings);
                $defaultTitleTemplate = '{provider}: {service_type_label} {ticket_number} — {passenger}';
                $titleTemplate = (string) ($settings['titleTemplate'] ?? $defaultTitleTemplate);
                if ($item->serviceType === ServiceType::ACCOMMODATION) {
                    $titleTemplate = trim((string) ($settings['accommodationTitleTemplate'] ?? '{provider}: проживание {hotel_name} — {passenger}'));
                    if ($titleTemplate === '') {
                        $titleTemplate = '{provider}: проживание {hotel_name} — {passenger}';
                    }
                }
                $titleSource = $source;
                if ($providerCode === 'aviasales'
                    && trim((string) ($source['short_order_id'] ?? '')) !== ''
                ) {
                    // Aviasales displays the first UUID block as its human-facing order ID.
                    // Use it in the existing {ticket_number} title slot without replacing the
                    // real ticket_number value used by field mappings and expense records.
                    $titleSource['ticket_number'] = (string) $source['short_order_id'];
                }
                $fields['title'] = $this->mapper->title($titleTemplate, $titleSource);
                $preserveTitleOnUpdate = !empty($settings['preserveTitleOnUpdate']);
                $hasExistingCard = $existing !== null && !empty($existing['bitrix_item_id']);
                if ($preserveTitleOnUpdate && $hasExistingCard) {
                    unset($fields['title']);
                }
                $fields['categoryId'] = (int) $route['categoryId'];
                if (!empty($route['stageId'])) {
                    $fields['stageId'] = (string) $route['stageId'];
                }

                $assignedById = (int) ($settings['assignedById'] ?? 0);
                if ($assignedById > 0) {
                    $fields['assignedById'] = $assignedById;
                }

                $fileMapping = !empty($settings['attachDocuments'])
                    ? $this->ticketFileMapping($mappings)
                    : null;
                $fileTarget = $fileMapping['target'] ?? null;
                $fileMultiple = !empty($fileMapping['multiple']);
                $fileState = null;
                if ($fileTarget !== null && $provider instanceof TravelDocumentProviderInterface) {
                    $previousPayload = json_decode((string) ($existing['payload_json'] ?? ''), true);
                    $previousPayload = is_array($previousPayload) ? $previousPayload : [];
                    $previousFile = is_array($previousPayload['_ticket_file'] ?? null)
                        ? $previousPayload['_ticket_file']
                        : [];
                    $sourceVersion = (string) ($source['document_revision'] ?? $source['updated_at'] ?? $source['created_at'] ?? '');
                    $previousFileStatus = (string) ($previousFile['status'] ?? '');
                    $sourceChanged = $sourceVersion !== ''
                        && ($previousFile['source_version'] ?? '') !== $sourceVersion;
                    $shouldFetchDocument = $existing === null
                        || empty($existing['bitrix_item_id'])
                        || ($existing['state'] ?? '') !== 'synced'
                        || ($previousFile['target'] ?? '') !== $fileTarget
                        || !empty($previousFile['multiple']) !== $fileMultiple
                        || $previousFileStatus === ''
                        || $previousFileStatus === 'error'
                        || $previousFileStatus === 'unavailable'
                        || ($previousFileStatus === 'available' && empty($previousFile['checksum']))
                        || $sourceChanged;

                    if ($shouldFetchDocument) {
                        try {
                            $document = $provider->ticketDocument($item);
                        } catch (TravelDocumentUnavailableException $exception) {
                            $stats['documentsUnavailable']++;
                            $document = null;
                            $fileState = [
                                'status' => 'unavailable',
                                'checksum' => null,
                                'target' => $fileTarget,
                                'multiple' => $fileMultiple,
                                'source_version' => $sourceVersion,
                                'checked_at' => gmdate(DATE_ATOM),
                                'reason' => substr($exception->getMessage(), 0, 500),
                            ];
                        } catch (\Throwable $exception) {
                            $stats['errors']++;
                            $stats['documentErrors']++;
                            $message = 'Файл билета ' . $externalId . ': ' . $exception->getMessage();
                            $itemErrors[] = $message;
                            $feedbackErrors[$externalId][] = $message;
                            $document = null;
                            $fileState = [
                                'status' => 'error',
                                'checksum' => null,
                                'target' => $fileTarget,
                                'multiple' => $fileMultiple,
                                'source_version' => $sourceVersion,
                                'checked_at' => gmdate(DATE_ATOM),
                                'error' => substr($exception->getMessage(), 0, 500),
                            ];
                        }
                        if ($document !== null) {
                            $fileState = [
                                'status' => 'available',
                                'checksum' => $document->checksum(),
                                'filename' => $document->filename,
                                'content_type' => $document->contentType,
                                'target' => $fileTarget,
                                'multiple' => $fileMultiple,
                                'source_version' => $sourceVersion,
                                'checked_at' => gmdate(DATE_ATOM),
                            ];
                            $mustUpload = $existing === null
                                || empty($existing['bitrix_item_id'])
                                || ($existing['state'] ?? '') !== 'synced'
                                || ($previousFile['target'] ?? '') !== $fileTarget
                                || !empty($previousFile['multiple']) !== $fileMultiple
                                || ($previousFile['checksum'] ?? '') !== $fileState['checksum'];
                            if ($mustUpload) {
                                $fileValue = $document->bitrixValue();
                                $fields[$fileTarget] = !empty($fileMapping['multiple']) ? [$fileValue] : $fileValue;
                                $stats['documents']++;
                            }
                        } elseif ($fileState !== null
                            && in_array(($fileState['status'] ?? ''), ['error', 'unavailable'], true)
                        ) {
                            // Карточку всё равно создаём или обновляем без файла.
                        } elseif (($previousFile['status'] ?? '') === 'available') {
                            $fileState = $previousFile;
                        } else {
                            $fileState = [
                                'status' => 'missing',
                                'checksum' => null,
                                'target' => $fileTarget,
                                'multiple' => $fileMultiple,
                                'source_version' => $sourceVersion,
                                'checked_at' => gmdate(DATE_ATOM),
                            ];
                        }
                    } else {
                        $fileState = $previousFile;
                    }
                    $source['_ticket_file'] = $fileState;
                }

                $additionalFileTarget = null;
                if (!empty($settings['attachDocuments']) && $provider instanceof TravelDocumentProviderInterface) {
                    foreach ($mappings as $mapping) {
                        if (($mapping['sourceField'] ?? '') !== 'additional_service_files' || empty($mapping['targetField'])) {
                            continue;
                        }
                        $additionalFileTarget = (string) $mapping['targetField'];
                        if ($additionalFileTarget === $fileTarget) {
                            throw new RuntimeException('Для документов допуслуг выберите отдельное множественное файловое поле.');
                        }
                        $collected = (new AdditionalDocumentSync())->collect(
                            $provider, $item,
                            is_array($previousPayload['_additional_files'] ?? null) ? $previousPayload['_additional_files'] : [],
                            $additionalFileTarget,
                            $existing === null || empty($existing['bitrix_item_id']) || ($existing['state'] ?? '') !== 'synced',
                        );
                        $source['_additional_files'] = $collected['state'];
                        if ($collected['value'] !== null) {
                            $fields[$additionalFileTarget] = $collected['value'];
                        }
                        $stats['documents'] += $collected['uploads'];
                        $stats['documentsUnavailable'] += $collected['unavailable'];
                        foreach ($collected['errors'] as $error) {
                            $stats['errors']++;
                            $stats['documentErrors']++;
                            $itemErrors[] = 'Документ допуслуги ' . $error;
                        }
                        break;
                    }
                }

                $checksumFields = $fields;
                if ($additionalFileTarget !== null) {
                    unset($checksumFields[$additionalFileTarget]);
                    $checksumFields['_additional_files'] = array_intersect_key(
                        $source['_additional_files'], array_flip(['target', 'status', 'checksum']),
                    );
                }
                if ($preserveTitleOnUpdate) {
                    // The title is intentionally immutable after creation, so title template
                    // changes must not cause a one-off update of every existing card.
                    unset($checksumFields['title']);
                }
                if ($fileTarget !== null) {
                    unset($checksumFields[$fileTarget]);
                    $checksumFields['_ticket_file'] = [
                        'target' => $fileTarget,
                        'multiple' => $fileMultiple,
                        'status' => $fileState['status'] ?? 'missing',
                        'checksum' => $fileState['checksum'] ?? null,
                    ];
                }
                $checksum = hash('sha256', json_encode($checksumFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                if ($existing !== null
                    && ($existing['state'] ?? '') === 'synced'
                    && hash_equals((string) ($existing['checksum'] ?? ''), $checksum)
                ) {
                    if (($previousPayload['_additional_items'] ?? []) !== ($source['_additional_items'] ?? [])
                        || ($additionalFileTarget !== null && ($previousPayload['_additional_files'] ?? null) !== ($source['_additional_files'] ?? null))
                        || ($fileTarget !== null
                        && isset($previousFile)
                        && is_array($fileState)
                        && $fileState !== $previousFile)
                    ) {
                        // Persist document retry metadata (for example checked_at)
                        // without making an unnecessary Bitrix24 card update.
                        $this->repository->saveSyncItem(
                            $memberId,
                            $providerCode,
                            $entityTypeId,
                            $externalId,
                            (int) $existing['bitrix_item_id'],
                            $checksum,
                            'synced',
                            $source,
                        );
                    }
                    $stats['unchanged']++;
                    continue;
                }

                try {
                    if ($existing === null || empty($existing['bitrix_item_id'])) {
                        if (empty($settings['createItems'])) {
                            $stats['skipped']++;
                            continue;
                        }
                        try {
                            $bitrixId = $bitrix->addItem($entityTypeId, $fields);
                        } catch (\Throwable $addException) {
                            // A timeout or broken HTTP/2 response does not prove that
                            // crm.item.add was not executed. Search by the mapped stable
                            // external ID before marking the item as failed, otherwise the
                            // next run can create a duplicate card.
                            $bitrixId = $this->recoverCreatedItem(
                                $bitrix,
                                $entityTypeId,
                                $identityTargetField,
                                $source[$identitySourceField] ?? null,
                                $addException,
                            );
                            $stats['recoveredCreates'] = (int) ($stats['recoveredCreates'] ?? 0) + 1;
                        }
                        $stats['created']++;
                    } else {
                        $bitrixId = (int) $existing['bitrix_item_id'];
                        if (empty($settings['updateItems'])) {
                            $stats['skipped']++;
                            continue;
                        }
                        $updateFields = $fields;
                        // The selected responsible person is a creation default.
                        // Never overwrite a responsible person that was changed in Bitrix24.
                        unset($updateFields['assignedById']);
                        $bitrix->updateItem($entityTypeId, $bitrixId, $updateFields);
                        $stats['updated']++;
                    }
                    $this->repository->saveSyncItem(
                        $memberId,
                        $providerCode,
                        $entityTypeId,
                        $externalId,
                        $bitrixId,
                        $checksum,
                        'synced',
                        $source,
                    );
                } catch (\Throwable $exception) {
                    $stats['errors']++;
                    $itemErrors[] = $externalId . ': ' . $exception->getMessage();
                    $feedbackErrors[$externalId][] = $exception->getMessage();
                    $this->repository->saveSyncItem(
                        $memberId,
                        $providerCode,
                        $entityTypeId,
                        $externalId,
                        isset($existing['bitrix_item_id']) ? (int) $existing['bitrix_item_id'] : null,
                        $checksum,
                        'error',
                        $source,
                        $exception->getMessage(),
                    );
                }
            }

            if (!empty($settings['expenseSync']['enabled'])) {
                $expenseItems = $batch['items'];
                $expenseIds = array_fill_keys(array_map(static fn ($item): string => $item->externalId, $expenseItems), true);
                foreach ($batch['items'] as $baseItem) {
                    if (!ServiceType::requiresTicketNumber($baseItem->serviceType)
                        || in_array((string) ($baseItem->data['order_id'] ?? ''), $batch['completeOrderIds'] ?? [], true)
                    ) {
                        continue;
                    }
                    $saved = $this->repository->syncItem($memberId, $providerCode, $entityTypeId, $baseItem->externalId);
                    $savedSource = json_decode((string) ($saved['payload_json'] ?? ''), true);
                    foreach ($savedSource['_additional_items'] ?? [] as $externalId => $record) {
                        if (!isset($expenseIds[$externalId])) {
                            $expenseItems[] = new \App\Domain\TravelItem($providerCode, $externalId, ServiceType::ADDITIONAL_SERVICE, $record['data']);
                            $expenseIds[$externalId] = true;
                        }
                    }
                }
                if (empty($settings['syncReturns'])) {
                    $expenseItems = array_values(array_filter(
                        $expenseItems,
                        fn (mixed $item): bool => !($item instanceof \App\Domain\TravelItem)
                            || !$this->isReturnOrCancellation($item->fields()),
                    ));
                }
                $this->syncExpenses(
                    $memberId,
                    $providerCode,
                    $bitrix,
                    $expenseItems,
                    $settings,
                    $trigger,
                    $stats,
                    $itemErrors,
                );
                $heartbeat(true);
            }

            if ($provider instanceof ProcessingFeedbackProviderInterface) {
                $heartbeat(true);
                $provider->sendProcessingFeedback($feedbackErrors);
            }

            if ($stats['errors'] === 0) {
                $this->repository->saveSyncCursor($memberId, $providerCode, $batch['nextCursor'] ?? null);
            }
            $state = $stats['errors'] > 0 ? 'partial' : 'success';
            $errorSummary = $itemErrors !== []
                ? implode('; ', array_slice($itemErrors, 0, 5))
                : ((int) ($stats['skippedMissingTicketNumber'] ?? 0) > 0
                    ? sprintf(
                        'Пропущено билетов без номера: %d. Они будут проверены при следующей синхронизации.',
                        (int) $stats['skippedMissingTicketNumber'],
                    )
                : ((int) ($stats['documentsUnavailable'] ?? 0) > 0
                    ? sprintf(
                        '%d маршрутных квитанций пока недоступны в API провайдера; повторная проверка будет выполнена при следующей синхронизации.',
                        (int) $stats['documentsUnavailable'],
                    )
                : ($stats['received'] === 0
                    ? ($providerCode === 'trivio'
                        ? (($stats['sourceMode'] ?? '') === 'queue_and_history'
                            ? "Trivio не вернул новых операций в очереди и заказов Client API за период {$stats['requestedFrom']} — {$stats['requestedTo']}."
                            : 'Trivio Integration API не вернул новых неподтверждённых операций. Диапазон дат к этому методу не применяется.')
                        : "API не вернул записей за период {$stats['requestedFrom']} — {$stats['requestedTo']}.")
                    : null)));
            $stats['heartbeatAt'] = gmdate(DATE_ATOM);
            $this->repository->finishSyncRun($runId, $state, $stats, $errorSummary);
            return ['runId' => $runId, 'state' => $state, 'stats' => $stats];
        } catch (\Throwable $exception) {
            $stats['errors'] = max(1, (int) ($stats['errors'] ?? 0));
            $this->repository->finishSyncRun($runId, 'failed', $stats, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @param list<\App\Domain\TravelItem> $items
     * @param list<string> $itemErrors
     */
    private function syncExpenses(
        string $memberId,
        string $providerCode,
        CrmClientInterface $bitrix,
        array $items,
        array $settings,
        string $trigger,
        array &$stats,
        array &$itemErrors,
    ): void {
        $expenseSettings = is_array($settings['expenseSync'] ?? null) ? $settings['expenseSync'] : [];
        $entityTypeId = (int) ($expenseSettings['entityTypeId'] ?? 0);
        $categoryId = $expenseSettings['categoryId'] ?? null;
        if ($entityTypeId <= 0 || $categoryId === null || (int) $categoryId < 0) {
            throw new RuntimeException('Для синхронизации затрат выберите смарт-процесс и воронку.');
        }

        $mappings = $this->repository->mappings($memberId, ExpenseCatalog::MAPPING_SCOPE);
        $identityTargetField = null;
        foreach ($mappings as $mapping) {
            if (($mapping['sourceField'] ?? null) === ExpenseCatalog::IDENTITY_FIELD
                && trim((string) ($mapping['targetField'] ?? '')) !== ''
            ) {
                $identityTargetField = (string) $mapping['targetField'];
                break;
            }
        }
        if ($identityTargetField === null) {
            throw new RuntimeException('Для затрат сопоставьте обязательное поле «Уникальный ID затраты».');
        }

        $providerDefinition = ProviderCatalog::get($providerCode);
        $aggregation = $this->expenseAggregator->aggregate(
            $providerCode,
            (string) ($providerDefinition['title'] ?? $providerCode),
            $items,
        );
        $stats['expenseSkipped'] += (int) ($aggregation['unmatchedAdditional'] ?? 0);

        foreach ($aggregation['records'] as $record) {
            $stats['expenseReceived']++;
            $source = $record->fields();
            $externalId = $record->externalId;
            $existing = $this->repository->syncItem($memberId, $providerCode, $entityTypeId, $externalId);
            if ($trigger === 'rescan'
                && !empty($existing['bitrix_item_id'])
                && !$bitrix->itemExists($entityTypeId, (int) $existing['bitrix_item_id'])
            ) {
                $existing = null;
            }

            $source = $this->reconcileExpenseFinancials($source, $existing);

            $fields = $this->mapper->map($source, $mappings);
            $titleTemplate = trim((string) ($expenseSettings['titleTemplate'] ?? 'Затрата: билет {ticket_number} — {passenger}'));
            if (($source['service_type'] ?? null) === ServiceType::ACCOMMODATION) {
                $titleTemplate = trim((string) ($expenseSettings['accommodationTitleTemplate']
                    ?? 'Затрата: проживание {hotel_name} — {passenger}'));
            }
            if ($titleTemplate === '') {
                $titleTemplate = ($source['service_type'] ?? null) === ServiceType::ACCOMMODATION
                    ? 'Затрата: проживание {hotel_name} — {passenger}'
                    : 'Затрата: билет {ticket_number} — {passenger}';
            }
            $fields['title'] = $this->mapper->title($titleTemplate, $source);
            if (!empty($expenseSettings['preserveTitleOnUpdate'])
                && $existing !== null
                && !empty($existing['bitrix_item_id'])
            ) {
                unset($fields['title']);
            }
            $fields['categoryId'] = (int) $categoryId;
            if (!empty($expenseSettings['stageId'])) {
                $fields['stageId'] = (string) $expenseSettings['stageId'];
            }
            $assignedById = (int) ($expenseSettings['assignedById'] ?? 0);
            if ($assignedById > 0) {
                $fields['assignedById'] = $assignedById;
            }

            $checksumFields = $fields;
            if (!empty($expenseSettings['preserveTitleOnUpdate'])) {
                unset($checksumFields['title']);
            }
            $checksum = hash('sha256', json_encode(
                $checksumFields,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            if ($existing !== null
                && ($existing['state'] ?? '') === 'synced'
                && hash_equals((string) ($existing['checksum'] ?? ''), $checksum)
            ) {
                $stats['expenseUnchanged']++;
                continue;
            }

            try {
                if ($existing === null || empty($existing['bitrix_item_id'])) {
                    if (empty($expenseSettings['createItems'])) {
                        $stats['expenseSkipped']++;
                        continue;
                    }
                    try {
                        $bitrixId = $bitrix->addItem($entityTypeId, $fields);
                    } catch (\Throwable $addException) {
                        $bitrixId = $this->recoverCreatedItem(
                            $bitrix,
                            $entityTypeId,
                            $identityTargetField,
                            $source[ExpenseCatalog::IDENTITY_FIELD] ?? null,
                            $addException,
                        );
                        $stats['recoveredCreates'] = (int) ($stats['recoveredCreates'] ?? 0) + 1;
                    }
                    $stats['expenseCreated']++;
                } else {
                    $bitrixId = (int) $existing['bitrix_item_id'];
                    if (empty($expenseSettings['updateItems'])) {
                        $stats['expenseSkipped']++;
                        continue;
                    }
                    $updateFields = $fields;
                    // Expense cards follow the same rule: assign only on creation.
                    unset($updateFields['assignedById']);
                    $bitrix->updateItem($entityTypeId, $bitrixId, $updateFields);
                    $stats['expenseUpdated']++;
                }
                $this->repository->saveSyncItem(
                    $memberId,
                    $providerCode,
                    $entityTypeId,
                    $externalId,
                    $bitrixId,
                    $checksum,
                    'synced',
                    $source,
                );
            } catch (\Throwable $exception) {
                $stats['errors']++;
                $stats['expenseErrors']++;
                $itemErrors[] = 'Затрата ' . $externalId . ': ' . $exception->getMessage();
                $this->repository->saveSyncItem(
                    $memberId,
                    $providerCode,
                    $entityTypeId,
                    $externalId,
                    isset($existing['bitrix_item_id']) ? (int) $existing['bitrix_item_id'] : null,
                    $checksum,
                    'error',
                    $source,
                    $exception->getMessage(),
                );
            }
        }
    }

    private function isReturnOrCancellation(array $source): bool
    {
        $code = strtolower(trim((string) ($source['status_code'] ?? '')));
        if (in_array($code, ['3', '4', '5', '8', '9', '11', '13'], true)) {
            return true;
        }
        $status = strtolower((string) ($source['status'] ?? ''));
        foreach (['return', 'cancel', 'annul', 'void', 'возврат', 'отмен', 'аннулир', 'обмен'] as $marker) {
            if (str_contains($status, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function reconcileExpenseFinancials(array $source, ?array $existing): array
    {
        if (!in_array((string) ($source['expense_status'] ?? ''), ['refunded', 'cancelled', 'exchanged'], true)) {
            return $source;
        }

        $previous = json_decode((string) ($existing['payload_json'] ?? ''), true);
        $previous = is_array($previous) ? $previous : [];
        $money = static function (mixed $value): ?float {
            return $value === null || $value === '' || !is_numeric($value) ? null : (float) $value;
        };

        $original = $money($source['original_amount'] ?? null)
            ?? $money($previous['original_amount'] ?? null)
            ?? $money($previous['gross_amount'] ?? null)
            ?? $money($previous['amount'] ?? null)
            ?? $money($source['gross_amount'] ?? null);
        $refund = $money($source['refund_amount'] ?? null);
        $net = $money($source['net_amount'] ?? null);

        // Some APIs express a completed full refund as a zero current amount.
        // It is safe to infer it only when we already know the earlier purchase amount.
        if ($refund === null
            && $original !== null
            && $original > 0
            && $money($source['gross_amount'] ?? null) === 0.0
        ) {
            $refund = $original;
            $source['calculation_status'] = 'calculated';
        }
        if ($net === null && $original !== null && $refund !== null) {
            $net = max(0.0, $original - abs($refund));
            if (($source['calculation_status'] ?? '') !== 'provider_exact') {
                $source['calculation_status'] = 'calculated';
            }
        }

        if ($net === null) {
            // Do not turn a cancellation into a zero expense without a documented
            // refund amount. Keep the last known expense and make uncertainty visible.
            $net = $money($previous['net_amount'] ?? null)
                ?? $money($previous['amount'] ?? null)
                ?? $money($source['amount'] ?? null);
            $source['calculation_status'] = 'refund_amount_unknown';
        }

        $source['original_amount'] = $original;
        $source['refund_amount'] = $refund !== null ? abs($refund) : null;
        $source['net_amount'] = $net;
        $source['amount'] = $net;
        $source['calculation_status_label'] = match ((string) ($source['calculation_status'] ?? '')) {
            'provider_exact' => 'Точный расчёт по данным провайдера',
            'calculated' => 'Рассчитано из суммы покупки и возврата',
            default => 'Сумма возврата не передана — требуется проверка',
        };

        return $source;
    }

    private function recoverCreatedItem(
        CrmClientInterface $bitrix,
        int $entityTypeId,
        ?string $identityTargetField,
        mixed $identityValue,
        \Throwable $addException,
    ): int {
        if (!$this->isIndeterminateCreateFailure($addException)
            || $identityTargetField === null
            || $identityValue === null
            || $identityValue === ''
        ) {
            throw $addException;
        }

        $lookupError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            usleep(250_000 * $attempt);
            try {
                $itemId = $bitrix->findItemIdByField(
                    $entityTypeId,
                    $identityTargetField,
                    $identityValue,
                );
                if ($itemId !== null) {
                    return $itemId;
                }
            } catch (\Throwable $exception) {
                $lookupError = $exception;
            }
        }

        $suffix = $lookupError !== null
            ? ' Проверка созданной карточки также завершилась ошибкой: ' . $lookupError->getMessage()
            : ' Карточка с этим внешним ID после запроса не найдена.';
        throw new RuntimeException($addException->getMessage() . $suffix, previous: $addException);
    }

    private function isIndeterminateCreateFailure(\Throwable $exception): bool
    {
        $messages = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }
        $message = strtoupper(implode(' ', $messages));
        foreach ([
            'TIMEOUT',
            'TIMED OUT',
            'IDLE TIMEOUT',
            'HTTP2 FRAMING',
            'CONNECTION RESET',
            'TRANSPORT ERROR',
            'BROKEN PIPE',
            'EMPTY REPLY',
        ] as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function ticketFileMapping(array $mappings): ?array
    {
        foreach ($mappings as $mapping) {
            if (($mapping['sourceField'] ?? '') !== 'ticket_file') {
                continue;
            }
            $target = trim((string) ($mapping['targetField'] ?? ''));
            return $target !== '' ? [
                'target' => $target,
                'multiple' => ($mapping['transform'] ?? '') === 'file_multiple',
            ] : null;
        }

        return null;
    }
}
