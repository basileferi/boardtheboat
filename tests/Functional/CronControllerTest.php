<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ClearsRateLimiters;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * l'url appelée par le planificateur d'infomaniak : invisible sans le bon jeton.
 */
final class CronControllerTest extends WebTestCase
{
    use ClearsRateLimiters;

    public function testAWrongTokenLooksLikeAMissingPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_cron/tick?token=nope');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNoTokenAtAllIsAlsoA404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_cron/tick');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheRightTokenRunsTheWorkerOnceAMinute(): void
    {
        $client = self::createClient();

        $client->request('GET', '/_cron/tick?token=test_cron_token_not_a_secret');
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/_cron/tick?token=test_cron_token_not_a_secret');
        self::assertResponseStatusCodeSame(429, 'a second call in the same minute is refused');
    }
}
