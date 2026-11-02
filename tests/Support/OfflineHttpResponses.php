<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * branché comme mock_response_factory du http_client en environnement de test : toute requête
 * sortante reçoit une réponse vide au lieu de partir sur internet. les tests ne touchent jamais
 * le réseau, même si un service appelle une vraie api par accident.
 */
final class OfflineHttpResponses
{
    public function __invoke(string $method, string $url): MockResponse
    {
        return str_ends_with($url, '/robots.txt')
            ? new MockResponse('', ['http_code' => 404])
            : new MockResponse('{}', ['response_headers' => ['content-type' => 'application/json']]);
    }
}
