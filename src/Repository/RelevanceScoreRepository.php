<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Company;
use App\Entity\RelevanceScore;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Stored relevance scores, always read for one user at a time.
 *
 * @extends ServiceEntityRepository<RelevanceScore>
 */
final class RelevanceScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelevanceScore::class);
    }

    public function findOneFor(User $user, Company $company): ?RelevanceScore
    {
        return $this->findOneBy(['user' => $user, 'company' => $company]);
    }

    /**
     * Everything already computed for one user, keyed by company id, so the ranker can decide
     * what to recompute with a single query instead of one lookup per company.
     *
     * @return array<string, RelevanceScore>
     */
    public function indexedByCompanyIdFor(User $user): array
    {
        /** @var list<RelevanceScore> $rows */
        $rows = $this->createQueryBuilder('rs')
            ->andWhere('rs.user = :user')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row->getCompany()->getId()] = $row;
        }

        return $indexed;
    }

    /**
     * The ranked companies shown on the recommendations page. The company is fetch-joined
     * because every row renders its name, city and sector.
     *
     * @return list<RelevanceScore>
     */
    public function topFor(User $user, int $limit = 20, int $minimumScore = 1): array
    {
        /** @var list<RelevanceScore> $rows */
        $rows = $this->createQueryBuilder('rs')
            ->addSelect('c')
            ->innerJoin('rs.company', 'c')
            ->andWhere('rs.user = :user')
            ->andWhere('rs.score >= :minimum')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->setParameter('minimum', $minimumScore)
            ->orderBy('rs.score', 'DESC')
            ->addOrderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
