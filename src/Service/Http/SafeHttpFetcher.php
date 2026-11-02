<?php

declare(strict_types=1);

namespace App\Service\Http;

use App\Dto\FetchResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * le seul point de passage pour aller lire une url qui vient d'un utilisateur ou d'un admin
 * (page carrières, site d'entreprise). tout ce qui touche au réseau "inconnu" passe ici.
 *
 * protections, dans l'ordre :
 *  - http/https seulement (pas de file://, ftp://, gopher://...) ;
 *  - pas d'ip privée, locale ou de métadonnées cloud, ni écrite en dur ni après résolution dns
 *    (NoPrivateNetworkHttpClient) : c'est la parade contre le ssrf ;
 *  - robots.txt respecté, une limite de débit par domaine, 3 redirections max ;
 *  - on lit au plus 1 Mo puis on coupe, et on abandonne au bout de 15 s.
 */
final readonly class SafeHttpFetcher
{
    public const int MAX_BYTES = 1_048_576;

    public function __construct(
        // NoPrivateNetworkHttpClient autour du client normal (config/services.yaml)
        #[Autowire(service: 'app.safe_http_client')]
        private HttpClientInterface $client,
        private RobotsPolicy $robots,
        #[Autowire(service: 'limiter.outbound_fetch')]
        private RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'monolog.logger.ingestion')]
        private LoggerInterface $logger,
        #[Autowire(param: 'app.http_fetcher_contact_url')]
        private string $contactUrl,
    ) {
    }

    /**
     * @throws UnsafeUrlException when the url is refused before any byte leaves the server
     */
    public function fetch(string $url): FetchResult
    {
        $parts = $this->assertSafe($url);
        $host = $parts['host'];

        $verdict = $this->robots->verdict($parts['scheme'].'://'.$host, $parts['path']);
        if (RobotsPolicy::UNREACHABLE === $verdict) {
            return FetchResult::failed($url, 'site injoignable');
        }
        if (RobotsPolicy::DISALLOWED === $verdict) {
            throw UnsafeUrlException::disallowedByRobots($url);
        }

        // un domaine à la fois, gentiment : on attend son tour plutôt que de marteler le site
        $this->limiter->create($host)->reserve()->wait();

        try {
            $response = $this->client->request('GET', $url, [
                'max_redirects' => 3,
                'timeout' => 10,
                'max_duration' => 15,
                'headers' => [
                    'User-Agent' => 'boardtheboat/1.0 (+'.$this->contactUrl.')',
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
            ]);

            $status = $response->getStatusCode();
            $body = '';
            foreach ($this->client->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) >= self::MAX_BYTES) {
                    $response->cancel();
                    break;
                }
            }

            $headers = $response->getHeaders(false);
            $finalUrl = $response->getInfo('url');

            return new FetchResult(
                url: $url,
                finalUrl: \is_string($finalUrl) ? $finalUrl : $url,
                status: $status,
                contentType: $headers['content-type'][0] ?? null,
                body: substr($body, 0, self::MAX_BYTES),
            );
        } catch (ExceptionInterface $exception) {
            // une ip privée découverte après résolution dns finit aussi ici
            $this->logger->info('fetch failed', ['host' => $host, 'reason' => $exception->getMessage()]);

            return FetchResult::failed($url, $exception->getMessage());
        }
    }

    /**
     * @return array{scheme: string, host: string, path: string}
     */
    public function assertSafe(string $url): array
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw UnsafeUrlException::malformed($url);
        }

        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw UnsafeUrlException::scheme($url);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw UnsafeUrlException::credentials($url);
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ('localhost' === $host || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw UnsafeUrlException::privateHost($url);
        }

        // ip écrite en dur : on la refuse tout de suite si elle est privée, réservée ou locale
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)
            && false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
            throw UnsafeUrlException::privateHost($url);
        }

        return ['scheme' => $scheme, 'host' => $host, 'path' => $parts['path'] ?? '/'];
    }
}
