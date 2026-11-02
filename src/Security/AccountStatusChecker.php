<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses disabled and unverified accounts at authentication time.
 *
 * Both checks live here rather than in a controller so that every entry point — the login
 * form, remember-me, and any future authenticator — is covered by the same rule.
 */
final readonly class AccountStatusChecker implements UserCheckerInterface
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $securityLogger,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            $this->securityLogger->warning('Login refused: account disabled', ['user' => $user->getUserIdentifier()]);

            throw new CustomUserMessageAccountStatusException('security.account.disabled');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            $this->securityLogger->info('Login refused: e-mail not verified', ['user' => $user->getUserIdentifier()]);

            throw new CustomUserMessageAccountStatusException('security.account.unverified');
        }
    }
}
