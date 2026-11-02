<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Company;
use App\Entity\CompanyBrief;
use App\Message\EnrichCompanyMessage;
use App\Repository\CompanyBriefRepository;
use App\Repository\CompanyRepository;
use App\Service\Enrichment\CareerPageChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * phase 1 : crée (ou retrouve) la fiche en attente, note ses sources, et vérifie la page
 * carrières à travers SafeHttpFetcher. la génération du contenu viendra en phase 2.
 */
#[AsMessageHandler]
final readonly class EnrichCompanyHandler
{
    public function __construct(
        private CompanyRepository $companies,
        private CompanyBriefRepository $briefs,
        private CareerPageChecker $checker,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(EnrichCompanyMessage $message): void
    {
        if (!Uuid::isValid($message->companyId)) {
            return;
        }

        $company = $this->companies->find(Uuid::fromString($message->companyId));
        if (!$company instanceof Company) {
            // supprimée entre-temps : rien à faire, et surtout pas d'erreur à rejouer
            return;
        }

        $brief = $this->briefs->findOneBy(['company' => $company]);
        if (null === $brief) {
            $brief = new CompanyBrief($company);
            $this->entityManager->persist($brief);
        }

        $brief->setSourceUrls(array_values(array_unique(array_filter([$company->getWebsite(), $company->getCareerUrl()]))));
        $company->markCareerPageChecked($this->checker->check($company));

        $this->entityManager->flush();
    }
}
