<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

use App\Dto\IngestionQuery;
use App\Dto\OfferData;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A free, documented job feed. Implementations map the third-party payload to OfferData and
 * keep only technology internships and apprenticeships; they never write to the database.
 */
#[AutoconfigureTag('app.job_source')]
interface JobSourceInterface
{
    /** Stable identifier stored in JobOffer::source, e.g. "themuse". */
    public function getName(): string;

    /** False when the source needs credentials that are not configured. */
    public function isEnabled(): bool;

    /**
     * @return iterable<OfferData>
     */
    public function fetch(IngestionQuery $query): iterable;
}
