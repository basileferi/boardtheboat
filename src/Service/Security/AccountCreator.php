<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Dto\RegistrationInput;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AppLocale;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The single place an account is created, whether from the sign-up form or the console.
 *
 * Every user gets a Profile immediately: the pipeline matches against a Profile, so an account
 * without one would simply be invisible to the automatic mode.
 */
final readonly class AccountCreator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $securityLogger,
    ) {
    }

    public function create(RegistrationInput $input): User
    {
        if (null === $input->email || null === $input->plainPassword) {
            throw new \InvalidArgumentException('Registration input must be validated before use.');
        }

        $user = $this->register($input->email, $input->plainPassword, $input->locale->value);
        $this->securityLogger->info('Account created', ['user' => $user->getUserIdentifier()]);

        return $user;
    }

    /**
     * @param list<string> $roles extra roles on top of ROLE_USER
     */
    public function register(string $email, string $plainPassword, string $locale = 'fr', array $roles = [], bool $verified = false): User
    {
        $user = new User($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setLocale(AppLocale::from($locale));
        $user->setRoles($roles);
        $user->setVerified($verified);

        $profile = new Profile($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $user;
    }
}
