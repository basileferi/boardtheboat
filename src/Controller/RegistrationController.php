<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegistrationInput;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Service\Security\AccountCreator;
use App\Service\Security\EmailVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

/**
 * Sign-up and e-mail confirmation.
 *
 * The whole controller is switched off by APP_REGISTRATION_OPEN (false in production, which is
 * single-client): a closed instance answers 404 rather than advertising a disabled feature.
 */
#[Route('/{_locale}', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
final class RegistrationController extends AbstractController
{
    public function __construct(
        #[Autowire(param: 'app.registration_open')]
        private readonly bool $registrationOpen,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        AccountCreator $accountCreator,
        EmailVerifier $emailVerifier,
        #[Autowire(service: 'limiter.registration')]
        RateLimiterFactoryInterface $registrationLimiter,
    ): Response {
        $this->denyIfClosed();

        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $input = new RegistrationInput();
        $form = $this->createForm(RegistrationFormType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$registrationLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                throw new TooManyRequestsHttpException(message: 'Too many sign-up attempts.');
            }

            $user = $accountCreator->create($input);
            $emailVerifier->sendConfirmation('app_verify_email', $user);

            $this->addFlash('success', 'security.register.check_your_inbox');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig', ['registrationForm' => $form]);
    }

    /**
     * The signed link identifies the account; the signature covers the id and the e-mail, so a
     * link cannot be replayed against another user or after an address change.
     */
    #[Route('/verify-email', name: 'app_verify_email', methods: ['GET'])]
    public function verifyUserEmail(
        Request $request,
        UserRepository $users,
        EmailVerifier $emailVerifier,
        LoggerInterface $securityLogger,
    ): Response {
        $id = $request->query->get('id');
        $user = \is_string($id) && Uuid::isValid($id) ? $users->find(Uuid::fromString($id)) : null;

        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        try {
            $emailVerifier->confirm($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $securityLogger->warning('E-mail confirmation refused', ['reason' => $exception->getReason()]);
            $this->addFlash('error', 'security.verify_email.failed');

            return $this->redirectToRoute('app_login');
        }

        $this->addFlash('success', 'security.verify_email.confirmed');

        return $this->redirectToRoute('app_login');
    }

    private function denyIfClosed(): void
    {
        if (!$this->registrationOpen) {
            throw new NotFoundHttpException();
        }
    }
}
