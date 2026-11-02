<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Application;
use App\Entity\Company;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\PipelineStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Applications, always scoped to their owner.
 *
 * Every method here takes the User explicitly rather than reading it from a token: a query
 * that can be called without an owner is an IDOR waiting to happen.
 *
 * @extends ServiceEntityRepository<Application>
 */
final class ApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Application::class);
    }

    public function findOneForTarget(User $owner, Company $company, ?JobOffer $offer): ?Application
    {
        return $this->findOneBy(['owner' => $owner, 'company' => $company, 'jobOffer' => $offer]);
    }

    /**
     * The whole pipeline in one query, grouped by status for the board.
     *
     * @return array<string, list<Application>>
     */
    public function boardFor(User $owner): array
    {
        /** @var list<Application> $rows */
        $rows = $this->createQueryBuilder('a')
            ->addSelect('c', 'o')
            ->innerJoin('a.company', 'c')
            ->leftJoin('a.jobOffer', 'o')
            ->andWhere('a.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->orderBy('a.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $board = [];
        foreach (PipelineStatus::cases() as $status) {
            $board[$status->value] = [];
        }

        foreach ($rows as $application) {
            $board[$application->getPipelineStatus()->value][] = $application;
        }

        return $board;
    }

    /**
     * Offers the user has already saved, as a set of offer ids, so the list can render the
     * save button in the right state without one query per row.
     *
     * @param list<JobOffer> $offers
     *
     * @return array<string, true>
     */
    public function savedOfferIds(User $owner, array $offers): array
    {
        if ([] === $offers) {
            return [];
        }

        /** @var list<array{offerId: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.jobOffer) AS offerId')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.jobOffer IN (:offers)')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->setParameter('offers', $offers)
            ->getQuery()
            ->getResult();

        $saved = [];
        foreach ($rows as $row) {
            $saved[strtolower((string) $row['offerId'])] = true;
        }

        return $saved;
    }

    /**
     * Follow-ups that have fallen due: the "À relancer" panel and the daily digest read this.
     *
     * @return list<Application>
     */
    public function dueFollowUps(User $owner, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        /** @var list<Application> $rows */
        $rows = $this->createQueryBuilder('a')
            ->addSelect('c')
            ->innerJoin('a.company', 'c')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.followUpAt IS NOT NULL')
            ->andWhere('a.followUpAt <= :now')
            ->andWhere('a.snoozedUntil IS NULL OR a.snoozedUntil <= :now')
            ->andWhere('a.pipelineStatus NOT IN (:closed)')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->setParameter('now', $now)
            ->setParameter('closed', [PipelineStatus::Interview->value, PipelineStatus::Rejected->value, PipelineStatus::Offer->value])
            ->orderBy('a.followUpAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return array<string, int> count per pipeline status, for the dashboard counters
     */
    public function countsByStatus(User $owner): array
    {
        /** @var list<array{status: PipelineStatus|string, total: int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.pipelineStatus AS status', 'COUNT(a.id) AS total')
            ->andWhere('a.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->groupBy('a.pipelineStatus')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach (PipelineStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach ($rows as $row) {
            // A scalar select on an enum-mapped column hydrates the enum itself, not its value.
            $status = $row['status'];
            $counts[$status instanceof PipelineStatus ? $status->value : (string) $status] = (int) $row['total'];
        }

        return $counts;
    }
}
