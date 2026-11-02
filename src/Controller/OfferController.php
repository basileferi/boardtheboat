<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\OfferSearchCriteria;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Form\OfferSearchType;
use App\Repository\ApplicationRepository;
use App\Repository\JobOfferRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The offer list and its detail pane.
 *
 * Both routes render the same template: with JavaScript, Turbo swaps only the "job-detail"
 * frame and advances the URL; without it, opening an offer is an ordinary page load that
 * happens to also render the list. The split view therefore needs no JavaScript to be usable.
 */
#[Route('/{_locale}/offers', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
#[IsGranted('ROLE_USER')]
final class OfferController extends AbstractController
{
    public function __construct(
        private readonly JobOfferRepository $offers,
        private readonly ApplicationRepository $applications,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('', name: 'app_offers', methods: ['GET'])]
    public function index(
        Request $request,
        #[Autowire(service: 'limiter.search')]
        RateLimiterFactoryInterface $searchLimiter,
    ): Response {
        return $this->renderSplitView($request, $searchLimiter, null);
    }

    #[Route('/{id}', name: 'app_offer_show', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['id' => 'id'])]
        JobOffer $offer,
        #[Autowire(service: 'limiter.search')]
        RateLimiterFactoryInterface $searchLimiter,
    ): Response {
        return $this->renderSplitView($request, $searchLimiter, $offer);
    }

    private function renderSplitView(Request $request, RateLimiterFactoryInterface $searchLimiter, ?JobOffer $selected): Response
    {
        $user = $this->getUser();
        $criteria = $this->criteriaFrom($request);
        // Built with an empty name so the query string stays flat and shareable
        // (?keyword=symfony&region=france) instead of offer_search[keyword]=…
        $form = $this->formFactory->createNamed('', OfferSearchType::class, $criteria, [
            'action' => $this->generateUrl('app_offers'),
        ]);
        $form->handleRequest($request);

        if (!$searchLimiter->create($this->limiterKey($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many searches.');
        }

        $criteria->cursor = $request->query->getString('cursor') ?: null;
        $page = $this->offers->search($criteria, $user instanceof User ? $user : null);

        // One query for the whole page rather than one per row.
        $saved = $user instanceof User ? $this->applications->savedOfferIds($user, $page->items) : [];
        $current = $selected ?? ($page->items[0] ?? null);

        return $this->render('offer/index.html.twig', [
            'searchForm' => $form,
            'criteria' => $criteria,
            'page' => $page,
            'total' => $this->offers->countMatching($criteria),
            'selected' => $current,
            'savedOfferIds' => $saved,
            'selectedIsSaved' => null !== $current && isset($saved[strtolower((string) $current->getId())]),
            'nextPageUrl' => $page->hasMore()
                ? $this->generateUrl('app_offers', [...$request->query->all(), 'cursor' => $page->nextCursor])
                : null,
        ]);
    }

    /**
     * Starts from the user's default region so the France / International switch is remembered
     * without a cookie of its own.
     */
    private function criteriaFrom(Request $request): OfferSearchCriteria
    {
        $criteria = new OfferSearchCriteria();
        $user = $this->getUser();

        if ($user instanceof User && null !== $profile = $user->getProfile()) {
            $criteria->region = $profile->getDefaultRegion();
        }

        if (!$request->query->has('region')) {
            return $criteria;
        }

        return $criteria;
    }

    private function limiterKey(Request $request): string
    {
        $user = $this->getUser();

        return $user instanceof User ? 'user-'.$user->getUserIdentifier() : 'ip-'.(string) $request->getClientIp();
    }
}
