<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

use App\Dto\CompanyData;
use App\Dto\OfferData;
use App\Entity\Company;
use App\Entity\JobOffer;
use App\Enum\CompanySize;
use App\Enum\OfferStatus;
use App\Repository\CompanyRepository;
use App\Repository\JobOfferRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes what the sources return. Idempotent by construction: offers are upserted on
 * (source, externalId), companies are matched against the global catalogue by SIREN, then
 * domain, then normalised name, so replaying a run updates rows instead of duplicating them.
 *
 * Holds a per-run map of companies created but not yet flushed, because the database cannot
 * find a row it has not been sent yet and two offers from one employer often share a batch.
 */
final class Ingestor
{
    /** @var array<string, Company> normalised name => company, for the current run */
    private array $pending = [];

    private bool $lastCreated = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyRepository $companies,
        private readonly JobOfferRepository $offers,
    ) {
    }

    /**
     * @return bool true when the offer is new
     */
    public function upsertOffer(OfferData $data): bool
    {
        $company = $this->company(new CompanyData(
            source: $data->source,
            externalId: mb_substr(Company::normalizeName($data->companyName), 0, 120),
            name: mb_substr($data->companyName, 0, 200),
            website: $data->companyWebsite,
            city: $this->city($data->location),
            country: $data->country,
        ));

        $offer = $this->offers->findOneBySource($data->source, $data->externalId);
        $created = null === $offer;
        if (null === $offer) {
            $offer = new JobOffer($company, $data->source, $data->externalId, mb_substr($data->title, 0, 255), $data->url);
            $this->entityManager->persist($offer);
        }

        $offer->setCompany($company);
        $offer->setTitle(mb_substr($data->title, 0, 255));
        $offer->setUrl(mb_substr($data->url, 0, 1000));
        $offer->setDescription($data->description);
        $offer->setLocation(null === $data->location ? null : mb_substr($data->location, 0, 180));
        $offer->setCountry($data->country);
        $offer->setWorkMode($data->workMode);
        $offer->setContractType($data->contractType);
        $offer->setPublishedAt($data->publishedAt ?? $offer->getPublishedAt() ?? new \DateTimeImmutable());
        $offer->setExpiresAt($data->expiresAt);
        $offer->setSalaryText(null === $data->salaryText ? null : mb_substr($data->salaryText, 0, 180));
        $offer->setStatus(null !== $data->expiresAt && $data->expiresAt < new \DateTimeImmutable() ? OfferStatus::Expired : OfferStatus::Open);

        return $created;
    }

    /**
     * @return bool true when the company is new
     */
    public function upsertCompany(CompanyData $data): bool
    {
        $this->company($data, true);

        return $this->lastCreated;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * Ends a run: flushes and forgets the pending map so the next run starts clean.
     */
    public function finish(): void
    {
        $this->entityManager->flush();
        $this->pending = [];
    }

    /**
     * @param bool $authoritative the registry's facts (NAF, size, city) overwrite what a job
     *                            board inferred; a job board never overwrites the registry
     */
    private function company(CompanyData $data, bool $authoritative = false): Company
    {
        $name = Company::normalizeName($data->name);
        $domain = null === $data->website ? null : Company::extractDomain($data->website);

        $company = $this->pending[$name] ?? $this->companies->findGlobalMatch($data->siren, $domain, $name);
        $isNew = null === $company;
        $this->lastCreated = $isNew;

        if (null === $company) {
            $company = new Company($data->name);
            $company->setSourceReference($data->source, $data->externalId);
            $this->entityManager->persist($company);
            $this->pending[$name] = $company;
        }

        if ($isNew || $authoritative) {
            $company->setSiren($data->siren ?? $company->getSiren());
            $company->setCity($data->city ?? $company->getCity());
            $company->setCountry($data->country ?? $company->getCountry());
            $company->setNafCode($data->nafCode ?? $company->getNafCode());
            $company->setSector($data->sector ?? $company->getSector());
            if (CompanySize::Unknown !== $data->size) {
                $company->setSize($data->size);
            }
        }

        if (null === $company->getWebsite() && null !== $domain && null !== $data->website && filter_var($data->website, \FILTER_VALIDATE_URL)) {
            $company->setWebsite(mb_substr($data->website, 0, 255));
        }

        return $company;
    }

    /**
     * First place of a multi-location string, without the remote marker.
     */
    private function city(?string $location): ?string
    {
        if (null === $location) {
            return null;
        }

        foreach (preg_split('/\s*[·,]\s*/u', $location) ?: [] as $part) {
            if ('' !== $part && 1 !== preg_match('/^(remote|flexible|anywhere)\b/iu', $part)) {
                return mb_substr($part, 0, 120);
            }
        }

        return null;
    }
}
