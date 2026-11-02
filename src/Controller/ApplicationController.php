<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Application;
use App\Entity\Company;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\PipelineStatus;
use App\Repository\ApplicationRepository;
use App\Security\Voter\OwnedEntityVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Saving and tracking. "Saving" an offer is not a separate concept: it creates an Application
 * in status Saved, which is the same row that later carries the applied date, the notes and
 * the follow-up schedule.
 *
 * Every write is a POST carrying a CSRF token, and every existing row goes through the voter
 * before it is touched.
 */
#[Route('/{_locale}', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
#[IsGranted('ROLE_USER')]
final class ApplicationController extends AbstractController
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/pipeline', name: 'app_pipeline', methods: ['GET'])]
    public function board(): Response
    {
        $user = $this->currentUser();

        return $this->render('application/board.html.twig', [
            'pipeline_statuses' => array_map(static fn (PipelineStatus $case): string => $case->value, PipelineStatus::cases()),
            'board' => $this->applications->boardFor($user),
            'counts' => $this->applications->countsByStatus($user),
            'due' => $this->applications->dueFollowUps($user),
        ]);
    }

    /**
     * Toggles the saved state of an offer. Answers a Turbo Stream so only the button and the
     * counter change, and a redirect when Turbo is not there.
     */
    #[Route('/offers/{id}/save', name: 'app_offer_save', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function toggleSave(Request $request, #[MapEntity(mapping: ['id' => 'id'])] JobOffer $offer): Response
    {
        $user = $this->currentUser();
        $this->assertCsrf($request, 'save-offer');

        $application = $this->applications->findOneForTarget($user, $offer->getCompany(), $offer);

        if (null === $application) {
            $application = new Application($user, $offer->getCompany(), $offer);
            $application->setTargetRole($offer->getTitle());
            $this->entityManager->persist($application);
            $saved = true;
        } else {
            $this->denyAccessUnlessGranted(OwnedEntityVoter::DELETE, $application);
            $this->entityManager->remove($application);
            $saved = false;
        }

        $this->entityManager->flush();

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('application/_save_button.stream.html.twig', [
                'offer' => $offer,
                'saved' => $saved,
            ]);
        }

        $this->addFlash('success', $saved ? 'application.saved' : 'application.unsaved');

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_offers'));
    }

    /**
     * Spontaneous applications: the target is a company, with no posted offer behind it.
     */
    #[Route('/companies/{id}/save', name: 'app_company_save', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function saveCompany(Request $request, #[MapEntity(mapping: ['id' => 'id'])] Company $company): Response
    {
        $user = $this->currentUser();
        $this->assertCsrf($request, 'save-company');
        $this->denyAccessUnlessGranted(OwnedEntityVoter::VIEW, $company);

        $application = $this->applications->findOneForTarget($user, $company, null);

        if (null === $application) {
            $application = new Application($user, $company);
            $this->entityManager->persist($application);
            $this->entityManager->flush();
            $this->addFlash('success', 'application.saved');
        }

        return $this->redirectToRoute('app_pipeline');
    }

    #[Route('/applications/{id}/status', name: 'app_application_status', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function changeStatus(Request $request, #[MapEntity(mapping: ['id' => 'id'])] Application $application): Response
    {
        $this->denyAccessUnlessGranted(OwnedEntityVoter::EDIT, $application);
        $this->assertCsrf($request, 'application-status');

        $status = PipelineStatus::tryFrom($request->request->getString('status'));
        if (null === $status) {
            throw $this->createNotFoundException('Unknown pipeline status.');
        }

        $profile = $application->getOwner()->getProfile();
        $application->changeStatus($status, null === $profile ? 7 : $profile->getRelanceDelayDays());
        $this->entityManager->flush();

        $this->addFlash('success', 'application.status_changed');

        return $this->redirectToRoute('app_pipeline');
    }

    #[Route('/applications/{id}/delete', name: 'app_application_delete', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'id'])] Application $application): Response
    {
        $this->denyAccessUnlessGranted(OwnedEntityVoter::DELETE, $application);
        $this->assertCsrf($request, 'application-delete');

        $this->entityManager->remove($application);
        $this->entityManager->flush();
        $this->addFlash('success', 'application.deleted');

        return $this->redirectToRoute('app_pipeline');
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
