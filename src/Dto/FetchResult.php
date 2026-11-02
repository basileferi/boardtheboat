<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * ce que SafeHttpFetcher a ramené d'une page. status 0 = la requête n'a pas abouti.
 */
final readonly class FetchResult
{
    public function __construct(
        public string $url,
        public string $finalUrl,
        public int $status,
        public ?string $contentType,
        public string $body,
        public ?string $error = null,
    ) {
    }

    public static function failed(string $url, string $error): self
    {
        return new self($url, $url, 0, null, '', $error);
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isHtml(): bool
    {
        return null !== $this->contentType && str_contains($this->contentType, 'html');
    }
}
