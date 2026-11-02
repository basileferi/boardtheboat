<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * levée quand une url est refusée avant même d'avoir envoyé une requête.
 */
final class UnsafeUrlException extends \RuntimeException
{
    public static function malformed(string $url): self
    {
        return new self(\sprintf('url illisible : "%s"', $url));
    }

    public static function scheme(string $url): self
    {
        return new self(\sprintf('seuls http et https sont autorisés : "%s"', $url));
    }

    public static function credentials(string $url): self
    {
        return new self(\sprintf('pas d\'identifiants dans une url : "%s"', $url));
    }

    public static function privateHost(string $url): self
    {
        return new self(\sprintf('adresse privée ou locale refusée : "%s"', $url));
    }

    public static function disallowedByRobots(string $url): self
    {
        return new self(\sprintf('robots.txt interdit cette page : "%s"', $url));
    }
}
