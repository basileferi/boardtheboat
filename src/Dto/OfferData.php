<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ContractType;
use App\Enum\WorkMode;

/**
 * One offer as a source adapter hands it over: already mapped to our vocabulary, already
 * reduced to plain text, not yet tied to a Company row.
 */
final readonly class OfferData
{
    public function __construct(
        public string $source,
        public string $externalId,
        public string $title,
        public string $url,
        public string $companyName,
        public string $description = '',
        public ?string $companyWebsite = null,
        public ?string $location = null,
        public ?string $country = null,
        public WorkMode $workMode = WorkMode::Unknown,
        public ContractType $contractType = ContractType::Internship,
        public ?\DateTimeImmutable $publishedAt = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?string $salaryText = null,
    ) {
    }
}
