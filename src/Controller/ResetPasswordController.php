<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Forgotten-password flow.
 *
 * Two rules shape it: the "check your inbox" page is shown whether or not the address exists,
 * so the form cannot be used to enumerate accounts; and the token travels in the URL only once,
 * being immediately moved into the session before the form is shown.
 */
#[Route('/{_locale}/password', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'app.mailer_from_address')]
        private readonly string $fromAddress,
        #[Autowire(param: 'app.mailer_from_name')]
        private readonly string $fromName,
    ) {
    }

    #[Route('/forgot', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        MailerInterface $mailer,
        UserRepository $users,
        LoggerInterface $securityLogger,
        #[Autowire(service: 'limiter.password_reset')]
        RateLimiterFactoryInterface $resetLimiter,
    ): Response {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$resetLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                throw new TooManyRequestsHttpException(message: 'Too many password reset requests.');
            }

            /** @var string $email */
            $email = $form->get('email')->getData();

            return $this->processSendingPasswordResetEmail($email, $mailer, $users, $securityLogger);
        }

        return $this->render('reset_password/request.html.twig', ['requestForm' => $form]);
    }

    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // A fake token keeps the page identical for an unknown address.
        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $this->getTokenObjectFromSession() ?? $this->resetPasswordHelper->generateFakeResetToken(),
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        LoggerInterface $securityLogger,
        ?string $token = null,
    ): Response {
        if (null !== $token) {
            // Never leave the token in the address bar: it would leak through the Referer
            // header and through the browser history.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $exception) {
            $securityLogger->warning('Password reset token refused', ['reason' => $exception->getReason()]);
            $this->addFlash('error', 'security.reset.invalid_token');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // The token dies with its use, whatever happens next.
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            if ($user instanceof User) {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
                $this->entityManager->flush();
                $securityLogger->info('Password reset completed', ['user' => $user->getUserIdentifier()]);
            }

            $this->cleanSessionAfterReset();
            $this->addFlash('success', 'security.reset.done');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', ['resetForm' => $form]);
    }

    private function processSendingPasswordResetEmail(
        string $email,
        MailerInterface $mailer,
        UserRepository $users,
        LoggerInterface $securityLogger,
    ): Response {
        $user = $users->findOneBy(['email' => $email]);

        if (!$user instanceof User || !$user->isActive()) {
            // Same answer as for a known address: the form must not reveal who has an account.
            return $this->redirectToRoute('app_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface $exception) {
            // Typically "a request is already pending": telling the visitor would leak just
            // as much as answering differently for an unknown address.
            $securityLogger->info('Password reset not generated', ['reason' => $exception->getReason()]);

            return $this->redirectToRoute('app_check_email');
        }

        $mailer->send(
            (new TemplatedEmail())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($user->getEmail())
                ->subject('boardtheboat — réinitialisation de votre mot de passe')
                ->htmlTemplate('email/reset_password.html.twig')
                ->textTemplate('email/reset_password.txt.twig')
                ->context(['resetToken' => $resetToken])
        );

        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_check_email');
    }
}
