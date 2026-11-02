<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\CspNonceProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes csp_nonce() so a template can mark the few scripts we do inline — the AssetMapper
 * import map and its polyfill — as trusted under the nonce-based policy.
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonceProvider $nonceProvider)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonceProvider->getNonce(...)),
        ];
    }
}
