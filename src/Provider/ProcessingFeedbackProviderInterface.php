<?php

declare(strict_types=1);

namespace App\Provider;

interface ProcessingFeedbackProviderInterface
{
    /** @param array<string, list<string>> $errorsByExternalId */
    public function sendProcessingFeedback(array $errorsByExternalId): void;
}
