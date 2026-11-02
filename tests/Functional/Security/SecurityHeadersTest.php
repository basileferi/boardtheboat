<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The headers are part of the product, so they are asserted like any other behaviour.
 *
 * The test environment runs the same strict policy as production (only the dev environment
 * relaxes it for the profiler toolbar), so a regression here fails the build.
 */
final class SecurityHeadersTest extends WebTestCase
{
    public function testTheStrictPolicyIsSentOnEveryHtmlResponse(): void
    {
        $client = self::createClient();
        $client->request('GET', '/fr');

        $headers = $client->getResponse()->headers;
        $csp = (string) $headers->get('Content-Security-Policy');

        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertSame('same-origin', $headers->get('Cross-Origin-Opener-Policy'));
        self::assertStringContainsString('geolocation=()', (string) $headers->get('Permissions-Policy'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'));
    }

    public function testTheInlineImportMapCarriesTheNonceFromThePolicy(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/fr');

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/'nonce-([^']+)'/", $csp, $matches), 'the policy must carry a nonce');

        $nonces = $crawler->filter('script[nonce]')->each(
            static fn (Crawler $script): ?string => $script->attr('nonce'),
        );

        self::assertNotEmpty($nonces, 'the import map must be marked with the nonce');
        foreach ($nonces as $nonce) {
            self::assertSame($matches[1], $nonce);
        }
    }

    public function testTheNonceChangesBetweenRequests(): void
    {
        $client = self::createClient();

        $client->request('GET', '/fr');
        $first = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        $client->request('GET', '/fr');
        $second = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertNotSame($first, $second, 'a reused nonce would defeat the policy');
    }
}
