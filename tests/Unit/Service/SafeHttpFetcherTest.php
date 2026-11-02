<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Http\RobotsPolicy;
use App\Service\Http\SafeHttpFetcher;
use App\Service\Http\UnsafeUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * test ssrf obligatoire : aucune de ces urls ne doit partir sur le réseau.
 */
final class SafeHttpFetcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'fichier local' => ['file:///etc/passwd'];
        yield 'ftp' => ['ftp://example.com/'];
        yield 'gopher' => ['gopher://example.com/'];
        yield 'loopback' => ['http://127.0.0.1/admin'];
        yield 'réseau privé 10/8' => ['http://10.0.0.5/'];
        yield 'réseau privé 192.168' => ['http://192.168.1.1/'];
        yield 'métadonnées cloud' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'localhost' => ['http://localhost:8000/'];
        yield 'identifiants dans l’url' => ['https://user:secret@example.com/'];
        yield 'pas une url' => ['not a url'];
    }

    #[DataProvider('refusedUrls')]
    public function testDangerousUrlsAreRefusedBeforeAnyRequest(string $url): void
    {
        $requests = 0;
        $fetcher = $this->fetcher(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('should never be reached');
        });

        try {
            $fetcher->fetch($url);
            self::fail('the url should have been refused: '.$url);
        } catch (UnsafeUrlException) {
            self::assertSame(0, $requests, 'nothing may leave the server for a refused url');
        }
    }

    public function testAPublicPageIsFetched(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/robots.txt')
            ? new MockResponse('', ['http_code' => 404])
            : new MockResponse('<html>carrières</html>', ['response_headers' => ['content-type' => 'text/html']]));

        $result = $fetcher->fetch('https://example.com/carrieres');

        self::assertTrue($result->isOk());
        self::assertTrue($result->isHtml());
        self::assertStringContainsString('carrières', $result->body);
    }

    public function testRobotsTxtIsRespected(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/robots.txt')
            ? new MockResponse("User-agent: *\nDisallow: /carrieres")
            : new MockResponse('should never be reached'));

        $this->expectException(UnsafeUrlException::class);
        $fetcher->fetch('https://example.com/carrieres/stage');
    }

    public function testTheBodyIsCapped(): void
    {
        $huge = str_repeat('a', SafeHttpFetcher::MAX_BYTES * 2);
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/robots.txt')
            ? new MockResponse('', ['http_code' => 404])
            : new MockResponse($huge));

        $result = $fetcher->fetch('https://example.com/');

        self::assertLessThanOrEqual(SafeHttpFetcher::MAX_BYTES, \strlen($result->body));
    }

    public function testRobotsRulesUseTheLongestMatchingPrefix(): void
    {
        $rules = RobotsPolicy::parse("User-agent: *\nDisallow: /jobs\nAllow: /jobs/public\n\nUser-agent: other\nDisallow: /");

        self::assertFalse(RobotsPolicy::decide($rules, '/jobs/internal'));
        self::assertTrue(RobotsPolicy::decide($rules, '/jobs/public/stage'));
        self::assertTrue(RobotsPolicy::decide($rules, '/about'), 'the block for another bot does not apply to us');
        self::assertFalse(RobotsPolicy::decide(null, '/'), 'unreachable robots.txt means we stay away');
    }

    private function fetcher(callable $responses): SafeHttpFetcher
    {
        $http = new MockHttpClient($responses);
        $limiter = new RateLimiterFactory(['id' => 'test', 'policy' => 'no_limit'], new InMemoryStorage());

        return new SafeHttpFetcher(
            $http,
            new RobotsPolicy($http, new ArrayAdapter()),
            $limiter,
            new NullLogger(),
            'https://boardtheboat.example/about',
        );
    }
}
