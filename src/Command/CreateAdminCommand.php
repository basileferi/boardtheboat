<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Security\AccountCreator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates the first administrator, or promotes an existing account.
 *
 * The password is asked for without echo and never accepted as an argument, so it cannot end
 * up in the shell history or in a process listing. Accounts created here are already verified:
 * there is nobody to send a confirmation link to on a fresh install.
 */
#[AsCommand(
    name: 'app:user:create-admin',
    description: 'Create an administrator account, or grant ROLE_ADMIN to an existing one.',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountCreator $accountCreator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail address of the administrator')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Interface locale (fr or en)', 'fr');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        if ($violations->count() > 0) {
            $io->error(\sprintf('"%s" is not a valid e-mail address.', $email));

            return Command::INVALID;
        }

        $existing = $this->users->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            return $this->promote($existing, $io);
        }

        $password = $this->askPassword($io, $input->isInteractive());
        if (null === $password) {
            return Command::INVALID;
        }

        $user = $this->accountCreator->register(
            $email,
            $password,
            (string) $input->getOption('locale'),
            [User::ROLE_ADMIN],
            verified: true,
        );

        $io->success(\sprintf('Administrator %s created.', $user->getUserIdentifier()));

        return Command::SUCCESS;
    }

    private function promote(User $user, SymfonyStyle $io): int
    {
        if ($user->isAdmin()) {
            $io->info(\sprintf('%s is already an administrator.', $user->getUserIdentifier()));

            return Command::SUCCESS;
        }

        $user->setRoles([...$user->getRoles(), User::ROLE_ADMIN]);
        $user->setActive(true);
        $user->setVerified(true);
        $this->entityManager->flush();

        $io->success(\sprintf('%s is now an administrator.', $user->getUserIdentifier()));

        return Command::SUCCESS;
    }

    /**
     * Interactive by default. When there is no terminal (a deploy script, a CI job), the
     * password is read from ADMIN_PASSWORD instead, which keeps it out of the process listing
     * and out of the shell history.
     */
    private function askPassword(SymfonyStyle $io, bool $interactive): ?string
    {
        if (!$interactive) {
            $fromEnv = getenv('ADMIN_PASSWORD');

            if (!\is_string($fromEnv) || mb_strlen($fromEnv) < 12) {
                $io->error('No terminal available: set ADMIN_PASSWORD (at least 12 characters) and run again.');

                return null;
            }

            return $fromEnv;
        }

        $question = (new Question('Password (at least 12 characters): '))
            ->setHidden(true)
            ->setHiddenFallback(false);

        $password = $io->askQuestion($question);
        if (!\is_string($password) || mb_strlen($password) < 12) {
            $io->error('The password must be at least 12 characters long.');

            return null;
        }

        $confirmation = (new Question('Repeat the password: '))
            ->setHidden(true)
            ->setHiddenFallback(false);

        if ($password !== $io->askQuestion($confirmation)) {
            $io->error('The two passwords do not match.');

            return null;
        }

        return $password;
    }
}
