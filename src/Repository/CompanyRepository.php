<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Company;
use App\Entity\User;
use App\Enum\CompanyVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * All company queries.
 *
 * Visibility is enforced here as well as in the voter: a query that forgets the owner would
 * leak private rows long before a voter ever sees them.
 *
 * @extends ServiceEntityRepository<Company>
 */
final class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    /**
     * Upsert key for the ingestion handlers and the demo seeder.
     */
    public function findOneBySource(string $source, string $externalId): ?Company
    {
        return $this->findOneBy(['source' => $source, 'externalId' => $externalId]);
    }

    /**
     * Deduplication, in the order of confidence: legal identifier, then registrable domain,
     * then the normalised name.
     */
    public function findDuplicateOf(Company $candidate): ?Company
    {
        $qb = $this->createQueryBuilder('c')->setMaxResults(1);
        $id = $candidate->getId();

        if (null !== $id) {
            $qb->andWhere('c.id <> :self')->setParameter('self', $id, UuidType::NAME);
        }

        if (null !== $candidate->getSiren()) {
            $match = (clone $qb)->andWhere('c.siren = :siren')
                ->setParameter('siren', $candidate->getSiren())
                ->getQuery()->getOneOrNullResult();

            if ($match instanceof Company) {
                return $match;
            }
        }

        if (null !== $candidate->getDomain()) {
            $match = (clone $qb)->andWhere('c.domain = :domain')
                ->setParameter('domain', $candidate->getDomain())
                ->getQuery()->getOneOrNullResult();

            if ($match instanceof Company) {
                return $match;
            }
        }

        $match = $qb->andWhere('c.normalizedName = :name')
            ->setParameter('name', $candidate->getNormalizedName())
            ->getQuery()->getOneOrNullResult();

        return $match instanceof Company ? $match : null;
    }

    /**
     * Everything the given user may see: the shared catalogue plus their own private rows.
     *
     * @return list<Company>
     */
    public function findVisibleTo(User $user, int $limit = 50): array
    {
        /** @var list<Company> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.owner IS NULL OR c.owner = :owner')
            ->setParameter('owner', $user->getId(), UuidType::NAME)
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * la liste du crud : le catalogue + mes entreprises privées, jamais celles des autres.
     * la recherche passe par un paramètre lié (jamais de concaténation sql).
     *
     * @return list<Company>
     */
    public function searchVisibleTo(User $user, ?string $query = null, bool $mineOnly = false, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('c')
            ->setParameter('owner', $user->getId(), UuidType::NAME)
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit);

        if ($mineOnly) {
            $qb->andWhere('c.owner = :owner');
        } else {
            $qb->andWhere('(c.owner IS NULL AND c.visibility = :catalogue) OR c.owner = :owner')
                ->setParameter('catalogue', CompanyVisibility::Catalogue->value);
        }

        if (null !== $query) {
            $qb->andWhere('c.normalizedName LIKE :q OR c.city LIKE :q')
                ->setParameter('q', '%'.addcslashes(Company::normalizeName($query), '%_').'%');
        }

        /** @var list<Company> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @return list<Company>
     */
    public function findOwnedBy(User $user): array
    {
        /** @var list<Company> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.owner = :owner')
            ->setParameter('owner', $user->getId(), UuidType::NAME)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Rows waiting for an administrator to accept them into the shared catalogue.
     *
     * @return list<Company>
     */
    public function findSuggested(): array
    {
        /** @var list<Company> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.visibility = :suggested')
            ->setParameter('suggested', CompanyVisibility::Suggested->value)
            ->orderBy('c.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Ingestion's deduplication: only global rows are candidates, so an import never merges
     * into a company a user typed privately. SIREN first, then domain, then normalised name.
     */
    public function findGlobalMatch(?string $siren, ?string $domain, string $normalizedName): ?Company
    {
        $keys = array_filter(['siren' => $siren, 'domain' => $domain, 'normalizedName' => $normalizedName]);

        foreach ($keys as $field => $value) {
            $match = $this->createQueryBuilder('c')
                ->andWhere('c.owner IS NULL')
                ->andWhere(\sprintf('c.%s = :value', $field))
                ->setParameter('value', $value)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($match instanceof Company) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @return array<string, int> source => number of catalogue companies
     */
    public function countCatalogueBySource(): array
    {
        /** @var list<array{source: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.source AS source', 'COUNT(c.id) AS total')
            ->andWhere('c.visibility = :catalogue')
            ->setParameter('catalogue', CompanyVisibility::Catalogue->value)
            ->groupBy('c.source')
            ->getQuery()
            ->getArrayResult();

        return array_map(intval(...), array_column($rows, 'total', 'source'));
    }

    public function deleteBySource(string $source): int
    {
        $deleted = $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.source = :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }

    public function countCatalogue(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.visibility = :catalogue')
            ->setParameter('catalogue', CompanyVisibility::Catalogue->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
