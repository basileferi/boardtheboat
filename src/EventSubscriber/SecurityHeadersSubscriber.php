<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\CspNonceProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the response headers that make the browser enforce our security assumptions.
 *
 * The Content-Security-Policy is nonce-based: no 'unsafe-inline' anywhere, which is what makes
 * it worth having. The only relaxation is the development environment, where the WebProfiler
 * toolbar injects its own inline script and style into every page — production and the test
 * suite both run the strict policy, so the tests actually verify what ships.
 */
final readonly class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CspNonceProvider $nonceProvider,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -128]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        if (!$this->isHtml($response->headers->get('Content-Type'))) {
            return;
        }

        $headers = $response->headers;
        $headers->set('Content-Security-Policy', $this->policy());
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // The application is a private workspace: only the public pages opt back in, by
        // removing this header in their controller.
        if (!$headers->has('X-Robots-Tag')) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    private function policy(): string
    {
        $nonce = $this->nonceProvider->getNonce();
        $scriptSrc = \sprintf("'self' 'nonce-%s'", $nonce);
        $styleSrc = "'self'";

        if ('dev' === $this->environment) {
            // The profiler toolbar is injected inline and cannot carry our nonce.
            $scriptSrc .= " 'unsafe-inline'";
            $styleSrc .= " 'unsafe-inline'";
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.$scriptSrc,
            'style-src '.$styleSrc,
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
        ]);
    }

    private function isHtml(?string $contentType): bool
    {
        return null !== $contentType && str_contains($contentType, 'html');
    }
}
