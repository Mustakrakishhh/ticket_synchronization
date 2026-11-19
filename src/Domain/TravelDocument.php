<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

final readonly class TravelDocument
{
    public function __construct(
        public string $filename,
        public string $content,
        public string $contentType,
    ) {
        if ($filename === '' || $content === '') {
            throw new InvalidArgumentException('Документ должен содержать имя и непустой контент.');
        }
    }

    public function checksum(): string
    {
        return hash('sha256', $this->content);
    }

    /** @return array{0: string, 1: string} */
    public function bitrixValue(): array
    {
        return [$this->filename, base64_encode($this->content)];
    }
}
