<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\RelevanceScoreRepository;
use App\Service\Matching\CompanyRanker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Entreprises recommandées": the companies worth writing to, ranked for this profile, with
 * the reason for each rank.
 *
 * Scores are normally produced by the nightly pipeline. Opening this page also refreshes the
 * pairs whose fingerprint moved, so a profile edited a minute ago shows correct ranks without
 * waiting for the next run.
 */
#[Route('/{_locale}/recommendations', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
#[IsGranted('ROLE_USER')]
final class RecommendationController extends AbstractController
{
    #[Route('', name: 'app_recommendations', methods: ['GET'])]
    public function index(CompanyRanker $ranker, RelevanceScoreRepository $scores): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $profile = $user->getProfile();
        $refreshed = null === $profile ? 0 : $ranker->rankAllFor($profile);

        return $this->render('recommendation/index.html.twig', [
            'profile' => $profile,
            'refreshed' => $refreshed,
            'scores' => null === $profile ? [] : $scores->topFor($user, $profile->getTopN()),
        ]);
    }
}
