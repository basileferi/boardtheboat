<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageDefaultsToFrench(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', $client->getRequest()->getLocale());
        self::assertSame('Trouver les entreprises à contacter', $crawler->filter('h1')->text());
    }

    public function testHomePageIsAvailableInEnglish(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/en');

        self::assertResponseIsSuccessful();
        self::assertSame('Find the companies worth contacting', $crawler->filter('h1')->text());
    }

    public function testUnsupportedLocaleIsNotRouted(): void
    {
        $client = self::createClient();
        $client->request('GET', '/de');

        self::assertResponseStatusCodeSame(404);
    }
}
