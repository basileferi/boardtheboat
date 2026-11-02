<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gives the templates and the Content-Security-Policy header the same per-request nonce.
 *
 * The value is kept on the Request so a sub-request (a rendered fragment, an error page)
 * reuses it instead of minting a second nonce the header would not cover.
 */
final readonly class CspNonceProvider
{
    public const string ATTRIBUTE = '_csp_nonce';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function getNonce(): string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            // CLI rendering (a mail template, for instance) has no policy to satisfy.
            return '';
        }

        $nonce = $request->attributes->get(self::ATTRIBUTE);
        if (\is_string($nonce) && '' !== $nonce) {
            return $nonce;
        }

        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set(self::ATTRIBUTE, $nonce);

        return $nonce;
    }
}
