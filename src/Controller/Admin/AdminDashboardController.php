<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Message\RefreshSourcesMessage;
use App\Repository\PipelineRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Entry point of the administration area.
 *
 * Guarded twice on purpose: access_control blocks the whole /admin prefix, and the attribute
 * below repeats the requirement so a future route added outside that prefix is still covered.
 */
#[Route('/{_locale}/admin', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminDashboardController extends AbstractController
{
    #[Route('', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(PipelineRunRepository $runs): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'runs' => $runs->findBy([], ['startedAt' => 'DESC'], 10),
        ]);
    }

    /**
     * Queues a refresh of every source; the worker (or the cron slice) runs it, never the request.
     */
    #[Route('/refresh', name: 'app_admin_refresh', methods: ['POST'])]
    public function refresh(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('admin-refresh', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $bus->dispatch(new RefreshSourcesMessage('admin'));
        $this->addFlash('success', 'admin.refresh.queued');

        return $this->redirectToRoute('app_admin_dashboard');
    }
}
