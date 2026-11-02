<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\OfferSearchCriteria;
use App\Dto\SearchPage;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\OfferStatus;
use App\Enum\Region;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * All offer queries. Nothing outside this class builds DQL against job_offer.
 *
 * Paging is keyset based: a page is fetched with a WHERE clause on the sort key rather than an
 * OFFSET, so page 40 costs the same as page 1. Every user-provided value is a bound parameter,
 * and the only thing that varies the SQL shape is the sort whitelist.
 *
 * @extends ServiceEntityRepository<JobOffer>
 */
final class JobOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobOffer::class);
    }

    /**
     * One page of offers plus the cursor for the next one.
     *
     * The company is fetch-joined: the list renders the employer name for every row, and a lazy
     * association would turn one query into one per offer.
     *
     * @return SearchPage<JobOffer>
     */
    public function search(OfferSearchCriteria $criteria, ?User $viewer = null, int $limit = OfferSearchCriteria::PER_PAGE): SearchPage
    {
        $qb = $this->createQueryBuilder('o')
            ->addSelect('c')
            ->innerJoin('o.company', 'c')
            ->setMaxResults($limit + 1);

        $this->applyFilters($qb, $criteria);

        $byRelevance = OfferSearchCriteria::SORT_RELEVANCE === $criteria->sort && $viewer instanceof User;
        if ($byRelevance) {
            $qb
                ->addSelect('COALESCE(rs.score, 0) AS HIDDEN relevance')
                ->leftJoin(
                    'App\Entity\RelevanceScore',
                    'rs',
                    'WITH',
                    'rs.company = c AND rs.user = :viewer',
                )
                ->setParameter('viewer', $viewer->getId(), UuidType::NAME)
                ->orderBy('relevance', 'DESC')
                ->addOrderBy('o.id', 'DESC');
        } else {
            $qb->orderBy('o.publishedAt', 'DESC')->addOrderBy('o.id', 'DESC');
        }

        $this->applyCursor($qb, $criteria->cursor, $byRelevance);

        /** @var list<JobOffer> $rows */
        $rows = $qb->getQuery()->getResult();

        $hasMore = \count($rows) > $limit;
        $items = $hasMore ? \array_slice($rows, 0, $limit) : $rows;
        $last = $items[\count($items) - 1] ?? null;

        return new SearchPage($items, $hasMore && null !== $last ? $this->cursorFor($last, $byRelevance, $viewer) : null);
    }

    public function countMatching(OfferSearchCriteria $criteria): int
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->innerJoin('o.company', 'c');

        $this->applyFilters($qb, $criteria);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.status = :open')
            ->setParameter('open', OfferStatus::Open->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Open offers published since the given date, optionally for one country.
     */
    public function countOpenSince(\DateTimeImmutable $since, ?string $country = null): int
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.status = :open')
            ->andWhere('o.publishedAt >= :since')
            ->setParameter('open', OfferStatus::Open->value)
            ->setParameter('since', $since);

        if (null !== $country) {
            $qb->andWhere('o.country = :country')->setParameter('country', $country);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countOpenInCountry(string $country): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.status = :open')
            ->andWhere('o.country = :country')
            ->setParameter('open', OfferStatus::Open->value)
            ->setParameter('country', $country)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, int> source => open offers
     */
    public function countOpenBySource(): array
    {
        /** @var list<array{source: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('o.source AS source', 'COUNT(o.id) AS total')
            ->andWhere('o.status = :open')
            ->setParameter('open', OfferStatus::Open->value)
            ->groupBy('o.source')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(intval(...), array_column($rows, 'total', 'source'));
    }

    /**
     * Publication dates of the open offers since a date, for the activity chart. Only the
     * scalar column is read, so a few thousand rows stay cheap.
     *
     * @return list<\DateTimeImmutable>
     */
    public function publicationDatesSince(\DateTimeImmutable $since): array
    {
        /** @var list<array{publishedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('o.publishedAt AS publishedAt')
            ->andWhere('o.status = :open')
            ->andWhere('o.publishedAt >= :since')
            ->setParameter('open', OfferStatus::Open->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'publishedAt');
    }

    /**
     * Most recent open offers, company joined to avoid one query per row.
     *
     * @return list<JobOffer>
     */
    public function latestOpen(int $limit, ?string $country = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->addSelect('c')
            ->innerJoin('o.company', 'c')
            ->andWhere('o.status = :open')
            ->setParameter('open', OfferStatus::Open->value)
            ->orderBy('o.publishedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $country) {
            $qb->andWhere('o.country = :country')->setParameter('country', $country);
        }

        /** @var list<JobOffer> $offers */
        $offers = $qb->getQuery()->getResult();

        return $offers;
    }

    /**
     * Closes offers a source stopped listing. Only old offers are touched: a source queried with
     * a limit returns a recent window, and an offer outside it may still be live.
     *
     * @param list<string> $seenExternalIds
     */
    public function expireUnseen(string $source, array $seenExternalIds, \DateTimeImmutable $publishedBefore): int
    {
        $qb = $this->createQueryBuilder('o')
            ->update()
            ->set('o.status', ':expired')
            ->andWhere('o.source = :source')
            ->andWhere('o.status = :open')
            ->andWhere('o.publishedAt < :before')
            ->setParameter('expired', OfferStatus::Expired->value)
            ->setParameter('open', OfferStatus::Open->value)
            ->setParameter('source', $source)
            ->setParameter('before', $publishedBefore);

        if ([] !== $seenExternalIds) {
            $qb->andWhere('o.externalId NOT IN (:seen)')->setParameter('seen', $seenExternalIds);
        }

        $updated = $qb->getQuery()->execute();

        return \is_int($updated) ? $updated : 0;
    }

    public function deleteBySource(string $source): int
    {
        $deleted = $this->createQueryBuilder('o')
            ->delete()
            ->andWhere('o.source = :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }

    /**
     * offres ouvertes publiées depuis moins de $days jours (la requête "plus récent que telle
     * date" de l'activité 4), utilisée par l'api. le paramètre est toujours lié, jamais concaténé.
     *
     * @return list<JobOffer>
     */
    public function findPublishedSince(int $days, int $limit = 50): array
    {
        /** @var list<JobOffer> $rows */
        $rows = $this->createQueryBuilder('o')
            ->addSelect('c')
            ->innerJoin('o.company', 'c')
            ->andWhere('o.status = :open')
            ->andWhere('o.publishedAt >= :since')
            ->setParameter('open', OfferStatus::Open->value)
            ->setParameter('since', new \DateTimeImmutable(\sprintf('-%d days', max(0, $days))))
            ->orderBy('o.publishedAt', 'DESC')
            ->setMaxResults(min(max(1, $limit), 200))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Upsert key used by every ingestion handler, which is what makes replaying a batch safe.
     */
    public function findOneBySource(string $source, string $externalId): ?JobOffer
    {
        return $this->findOneBy(['source' => $source, 'externalId' => $externalId]);
    }

    /**
     * @param QueryBuilder $qb aliased "o" for the offer and "c" for its company
     */
    private function applyFilters(QueryBuilder $qb, OfferSearchCriteria $criteria): void
    {
        $qb->andWhere('o.status = :status')->setParameter('status', $criteria->status->value);

        $qb->andWhere(Region::France === $criteria->region ? 'o.country = :fr' : 'o.country <> :fr')
            ->setParameter('fr', 'FR');

        if (null !== $criteria->keyword) {
            // Full-text in boolean mode so short queries and several words behave predictably;
            // the terms themselves are always a bound parameter.
            $qb->andWhere("MATCH_AGAINST(o.title, o.description, :keyword, 'boolean') > 0")
                ->setParameter('keyword', $this->toBooleanQuery($criteria->keyword));
        }

        if (null !== $criteria->company) {
            $qb->andWhere('c.normalizedName LIKE :company')
                ->setParameter('company', '%'.$this->escapeLike(\App\Entity\Company::normalizeName($criteria->company)).'%');
        }

        if (null !== $criteria->location) {
            $qb->andWhere('o.location LIKE :location OR c.city LIKE :location')
                ->setParameter('location', '%'.$this->escapeLike($criteria->location).'%');
        }

        if ([] !== $criteria->contractTypes) {
            $qb->andWhere('o.contractType IN (:contractTypes)')
                ->setParameter('contractTypes', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $criteria->contractTypes));
        }

        if ([] !== $criteria->workModes) {
            $qb->andWhere('o.workMode IN (:workModes)')
                ->setParameter('workModes', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $criteria->workModes));
        }
    }

    private function applyCursor(QueryBuilder $qb, ?string $cursor, bool $byRelevance): void
    {
        $parts = $this->decodeCursor($cursor);
        if (null === $parts) {
            return;
        }

        [$sortValue, $id] = $parts;

        if ($byRelevance) {
            $qb->andWhere('(COALESCE(rs.score, 0) < :cursorScore OR (COALESCE(rs.score, 0) = :cursorScore AND o.id < :cursorId))')
                ->setParameter('cursorScore', (int) $sortValue)
                ->setParameter('cursorId', $id, UuidType::NAME);

            return;
        }

        $qb->andWhere('(o.publishedAt < :cursorDate OR (o.publishedAt = :cursorDate AND o.id < :cursorId))')
            ->setParameter('cursorDate', new \DateTimeImmutable($sortValue))
            ->setParameter('cursorId', $id, UuidType::NAME);
    }

    private function cursorFor(JobOffer $offer, bool $byRelevance, ?User $viewer): ?string
    {
        $id = $offer->getId();
        if (null === $id) {
            return null;
        }

        if ($byRelevance) {
            $score = null === $viewer ? 0 : $this->relevanceScoreFor($offer, $viewer);

            return base64_encode('s|'.$score.'|'.$id->toRfc4122());
        }

        $publishedAt = $offer->getPublishedAt();
        if (null === $publishedAt) {
            return null;
        }

        return base64_encode('d|'.$publishedAt->format('Y-m-d H:i:s').'|'.$id->toRfc4122());
    }

    private function relevanceScoreFor(JobOffer $offer, User $viewer): int
    {
        $score = $this->getEntityManager()
            ->createQuery('SELECT rs.score FROM App\Entity\RelevanceScore rs WHERE rs.company = :company AND rs.user = :user')
            ->setParameter('company', $offer->getCompany())
            ->setParameter('user', $viewer)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return \is_array($score) ? (int) $score['score'] : 0;
    }

    /**
     * @return array{string, Uuid}|null the sort value and the identifier the page resumes after
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }

        $decoded = base64_decode($cursor, true);
        if (false === $decoded) {
            return null;
        }

        $parts = explode('|', $decoded, 3);
        if (3 !== \count($parts) || !Uuid::isValid($parts[2])) {
            return null;
        }

        return [$parts[1], Uuid::fromString($parts[2])];
    }

    /**
     * Turns a human query into a MySQL boolean-mode expression: every word is required and may
     * be completed, so "dev php" matches "développeur PHP" without matching everything.
     */
    private function toBooleanQuery(string $keyword): string
    {
        $words = preg_split('/[^\p{L}\p{N}_]+/u', $keyword, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];

        foreach (\array_slice($words, 0, 8) as $word) {
            $terms[] = '+'.$word.'*';
        }

        return implode(' ', $terms);
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
