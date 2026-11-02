<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\ResetPasswordRequest;
use App\Service\Security\AccountCreator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * A reset token must be usable exactly once, which means the row really has to leave the
 * database when the flow consumes it. This is asserted at the persistence level because the
 * removal is a DQL DELETE, not a managed entity removal, and therefore bypasses the unit of work.
 */
final class ResetPasswordRequestRepositoryTest extends KernelTestCase
{
    use ResetDatabase;

    public function testConsumingATokenRemovesItsRow(): void
    {
        self::bootKernel();

        /** @var AccountCreator $creator */
        $creator = self::getContainer()->get(AccountCreator::class);
        $user = $creator->register('reset-repo@example.test', 'correct-horse-battery-staple', 'fr', [], true);

        /** @var ResetPasswordHelperInterface $helper */
        $helper = self::getContainer()->get(ResetPasswordHelperInterface::class);
        $token = $helper->generateResetToken($user);

        self::assertSame(1, $this->rowCount());

        $helper->removeResetRequest($token->getToken());

        self::assertSame(0, $this->rowCount(), 'a consumed reset token must not survive in the database');
    }

    private function rowCount(): int
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return \count($entityManager->getRepository(ResetPasswordRequest::class)->findAll());
    }
}
