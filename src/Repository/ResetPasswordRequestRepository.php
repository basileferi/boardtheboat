<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\Clock;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * Storage for outstanding password-reset requests.
 *
 * The bundle ships a trait that implements this interface, but its user-scoped queries bind the
 * User object as a plain parameter. With a UUID primary key stored as BINARY(16) that comparison
 * silently matches nothing: the throttling check always saw "no previous request", and consuming
 * a token never deleted its row, so a reset link stayed replayable. Every query below therefore
 * binds the identifier explicitly with the "uuid" type. Covered by
 * tests/Integration/ResetPasswordRequestRepositoryTest.php.
 *
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
final class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        return new ResetPasswordRequest($this->assertUser($user), $expiresAt, $selector, $hashedToken);
    }

    public function getUserIdentifier(object $user): string
    {
        return (string) $this->assertUser($user)->getId();
    }

    public function persistResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->getEntityManager()->persist($resetPasswordRequest);
        $this->getEntityManager()->flush();
    }

    public function findResetPasswordRequest(string $selector): ?ResetPasswordRequestInterface
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        $request = $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->setParameter('user', $this->assertUser($user)->getId(), UuidType::NAME)
            ->orderBy('r.requestedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($request instanceof ResetPasswordRequest && !$request->isExpired()) {
            return $request->getRequestedAt();
        }

        return null;
    }

    /**
     * Consuming one token invalidates every outstanding request of that account, which is what
     * makes a reset link single-use.
     */
    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->removeRequests($resetPasswordRequest->getUser());
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        $deleted = $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.expiresAt <= :threshold')
            ->setParameter('threshold', Clock::get()->now()->modify('-1 week'))
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }

    /**
     * Drops every pending request of one account, for instance when an administrator disables it.
     */
    public function removeRequests(object $user): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.user = :user')
            ->setParameter('user', $this->assertUser($user)->getId(), UuidType::NAME)
            ->getQuery()
            ->execute();
    }

    private function assertUser(object $user): User
    {
        if (!$user instanceof User) {
            throw new \InvalidArgumentException(\sprintf('Password reset requests only exist for "%s".', User::class));
        }

        return $user;
    }
}
