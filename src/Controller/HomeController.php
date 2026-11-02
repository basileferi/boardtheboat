<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CompanyRepository;
use App\Repository\JobOfferRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class HomeController extends AbstractController
{
    #[Route(
        path: '/{_locale}',
        name: 'app_home',
        requirements: ['_locale' => 'fr|en'],
        defaults: ['_locale' => 'fr'],
        methods: ['GET'],
    )]
    public function index(CacheInterface $cache, JobOfferRepository $offers, CompanyRepository $companies): Response
    {
        // Public page: the live counts are shared by every visitor, so they are cached briefly.
        $counts = $cache->get('home.counts', static function (ItemInterface $item) use ($offers, $companies): array {
            $item->expiresAfter(600);

            return [
                'offers' => $offers->countOpen(),
                'france' => $offers->countOpenInCountry('FR'),
                'companies' => $companies->countCatalogue(),
                'sources' => \count($offers->countOpenBySource()),
            ];
        });

        return $this->render('home/index.html.twig', ['counts' => $counts]);
    }
}
