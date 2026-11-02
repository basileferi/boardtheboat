<?php

declare(strict_types=1);

namespace App\Service\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * lit robots.txt une fois par jour et par site, et dit si on a le droit d'aller sur un chemin.
 *
 * volontairement simple : on regarde les blocs "User-agent: *" et "User-agent: boardtheboat",
 * et les règles Disallow / Allow par préfixe (la plus longue gagne). pas de robots.txt = tout
 * est permis, robots.txt injoignable = on s'abstient.
 */
final readonly class RobotsPolicy
{
    public const string ALLOWED = 'allowed';
    public const string DISALLOWED = 'disallowed';
    public const string UNREACHABLE = 'unreachable';

    public function __construct(
        #[Autowire(service: 'app.safe_http_client')]
        private HttpClientInterface $client,
        private CacheInterface $cache,
    ) {
    }

    /**
     * "unreachable" est distinct de "disallowed" : un site qui ne répond pas n'est pas un site
     * qui nous interdit, et la page carrières ne doit pas être marquée "bloquée" pour autant.
     */
    public function verdict(string $origin, string $path): string
    {
        $rules = $this->cache->get('robots.'.hash('xxh3', $origin), function (ItemInterface $item) use ($origin): ?array {
            $rules = $this->download($origin);
            // un site injoignable est retenté dans l'heure, pas demain
            $item->expiresAfter(null === $rules ? 3600 : 86400);

            return $rules;
        });

        if (null === $rules) {
            return self::UNREACHABLE;
        }

        return self::decide($rules, '' === $path ? '/' : $path) ? self::ALLOWED : self::DISALLOWED;
    }

    /**
     * @param array{allow: list<string>, disallow: list<string>}|null $rules null = unreachable
     */
    public static function decide(?array $rules, string $path): bool
    {
        if (null === $rules) {
            return false;
        }

        $best = '';
        $allowed = true;

        foreach (['disallow' => false, 'allow' => true] as $kind => $verdict) {
            foreach ($rules[$kind] as $prefix) {
                if ('' !== $prefix && str_starts_with($path, $prefix) && \strlen($prefix) >= \strlen($best)) {
                    $best = $prefix;
                    $allowed = $verdict;
                }
            }
        }

        return $allowed;
    }

    /**
     * @return array{allow: list<string>, disallow: list<string>}
     */
    public static function parse(string $robots): array
    {
        $rules = ['allow' => [], 'disallow' => []];
        $applies = false;
        $previousWasAgent = false;

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));
            if (!str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ('user-agent' === $field) {
                $agent = strtolower($value);
                $matches = '*' === $agent || str_contains($agent, 'boardtheboat');
                // plusieurs User-agent d'affilée partagent le même bloc
                $applies = $previousWasAgent ? ($applies || $matches) : $matches;
                $previousWasAgent = true;

                continue;
            }

            $previousWasAgent = false;
            if ($applies && \in_array($field, ['allow', 'disallow'], true)) {
                $rules[$field][] = $value;
            }
        }

        return $rules;
    }

    /**
     * @return array{allow: list<string>, disallow: list<string>}|null
     */
    private function download(string $origin): ?array
    {
        try {
            $response = $this->client->request('GET', rtrim($origin, '/').'/robots.txt', ['timeout' => 5, 'max_redirects' => 2]);
            $status = $response->getStatusCode();

            if ($status >= 400 && $status < 500) {
                return ['allow' => [], 'disallow' => []];
            }

            return $status >= 500 ? null : self::parse($response->getContent(false));
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
