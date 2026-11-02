<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * comptes actifs qui ont un profil : ce sont eux que le pipeline classe chaque nuit.
     * le profil est chargé dans la même requête (pas de n+1).
     *
     * @return list<User>
     */
    public function findWithProfile(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->addSelect('p')
            ->innerJoin('u.profile', 'p')
            ->andWhere('u.active = true')
            ->getQuery()
            ->getResult();

        return $users;
    }
}
