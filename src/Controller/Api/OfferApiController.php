<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\JobOffer;
use App\Repository\JobOfferRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * lecture seule des offres en json. /api/offers?days=7 renvoie les offres publiées cette
 * semaine : c'est la requête personnalisée "plus récent que" du cours 4 exposée en api.
 */
#[Route('/api/offers')]
#[IsGranted('ROLE_USER')]
final class OfferApiController extends AbstractController
{
    private const array READ = ['groups' => ['offer:read']];

    #[Route('', name: 'api_offer_recent', methods: ['GET'])]
    public function recent(
        JobOfferRepository $offers,
        #[MapQueryParameter] int $days = 7,
        #[MapQueryParameter] int $limit = 50,
    ): JsonResponse {
        return $this->json($offers->findPublishedSince($days, $limit), context: self::READ);
    }

    #[Route('/{id}', name: 'api_offer_show', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['id' => 'id'])] JobOffer $offer): JsonResponse
    {
        return $this->json($offer, context: self::READ);
    }
}
