<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Dashboard\DashboardReadout;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The cockpit: what the automatic mode found, what is waiting for a follow-up, and how far
 * the pipeline got. It answers one question — what do I do next — before anything else.
 */
#[Route('/{_locale}', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(DashboardReadout $readout): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('dashboard/index.html.twig', $readout->for($user));
    }
}
