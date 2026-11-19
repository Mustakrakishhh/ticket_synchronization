<?php

declare(strict_types=1);

namespace App\Provider;

use App\Domain\TravelDocument;
use App\Domain\TravelItem;

interface TravelDocumentProviderInterface
{
    public function ticketDocument(TravelItem $item): ?TravelDocument;
}
