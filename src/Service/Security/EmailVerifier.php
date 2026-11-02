<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelper;

/**
 * Sends and checks the signed e-mail confirmation link.
 *
 * The link carries a signature over the user id and e-mail, so it cannot be replayed for
 * another account and stops working as soon as the address changes.
 */
final readonly class EmailVerifier
{
    public function __construct(
        #[Autowire(service: 'symfonycasts.verify_email.helper')]
        private VerifyEmailHelper $helper,
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
        #[Autowire(param: 'app.mailer_from_address')]
        private string $fromAddress,
        #[Autowire(param: 'app.mailer_from_name')]
        private string $fromName,
    ) {
    }

    public function sendConfirmation(string $verifyRouteName, User $user): void
    {
        $signature = $this->helper->generateSignature(
            $verifyRouteName,
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => (string) $user->getId()],
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($user->getEmail())
            ->subject('boardtheboat — confirmation de votre adresse e-mail')
            ->htmlTemplate('email/confirmation.html.twig')
            ->textTemplate('email/confirmation.txt.twig')
            ->context([
                'signedUrl' => $signature->getSignedUrl(),
                'expiresAtMessageKey' => $signature->getExpirationMessageKey(),
                'expiresAtMessageData' => $signature->getExpirationMessageData(),
            ]);

        $this->mailer->send($email);
    }

    /**
     * @throws VerifyEmailExceptionInterface when the signature is invalid, expired or reused
     */
    public function confirm(Request $request, User $user): void
    {
        $this->helper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), $user->getEmail());

        $user->setVerified(true);
        $this->entityManager->flush();
    }
}
