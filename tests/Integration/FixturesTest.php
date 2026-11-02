<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\UserFixtures;
use App\Entity\Application;
use App\Entity\Company;
use App\Entity\JobOffer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * on charge vraiment les fixtures (comme `doctrine:fixtures:load`) et on compte ce qui arrive en
 * base, comme dans le cours 14 : "on en a mis 10 avec les fixtures".
 */
final class FixturesTest extends KernelTestCase
{
    use ResetDatabase;

    public function testTheDemoGroupFillsEveryTable(): void
    {
        $this->load('demo');

        self::assertSame(12, $this->rows(User::class), '2 comptes en dur + 10 faker');
        self::assertSame(15, $this->rows(Company::class));
        self::assertSame(40, $this->rows(JobOffer::class));
        self::assertSame(4, $this->rows(Application::class));
    }

    public function testTheUsersGroupOnlyCreatesAccounts(): void
    {
        $this->load('users');

        self::assertSame(12, $this->rows(User::class));
        self::assertSame(0, $this->rows(JobOffer::class), 'le groupe users ne doit pas toucher aux offres');
    }

    public function testTheHardCodedAccountsCanSignIn(): void
    {
        $this->load('users');

        $admin = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@test.fr']);
        self::assertInstanceOf(User::class, $admin);
        self::assertTrue($admin->isAdmin());
        self::assertTrue($admin->isVerified());

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($admin, UserFixtures::PASSWORD));
    }

    private function load(string $group): void
    {
        $application = new ConsoleApplication(self::bootKernel());
        $tester = new CommandTester($application->find('doctrine:fixtures:load'));
        $tester->execute(['--group' => [$group], '--no-interaction' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class): int
    {
        return $this->entityManager()->getRepository($class)->count([]);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager;
    }
}
