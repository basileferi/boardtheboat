<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Company;
use App\Entity\CompanyBrief;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyBrief>
 */
final class CompanyBriefRepository extends ServiceEntityRepository
{
    /** une fiche plus vieille que 30 jours est refaite */
    public const string MAX_AGE = 'P30D';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyBrief::class);
    }

    public function hasFreshBrief(Company $company): bool
    {
        $brief = $this->findOneBy(['company' => $company]);

        return $brief instanceof CompanyBrief && !$brief->isStale(new \DateInterval(self::MAX_AGE));
    }
}
