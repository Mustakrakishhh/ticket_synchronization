<?php

declare(strict_types=1);

namespace App\Provider;

use App\Domain\TravelDocument;
use App\Domain\TravelItem;

interface TravelDocumentCollectionProviderInterface extends TravelDocumentProviderInterface
{
    /** @return list<TravelDocument> */
    public function ticketDocuments(TravelItem $item): array;
}
