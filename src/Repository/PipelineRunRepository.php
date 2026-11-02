<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PipelineRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PipelineRun>
 */
final class PipelineRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PipelineRun::class);
    }

    public function latest(): ?PipelineRun
    {
        $run = $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run instanceof PipelineRun ? $run : null;
    }
}
