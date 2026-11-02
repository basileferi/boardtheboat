<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

/**
 * Turns third-party HTML into a short plain-text excerpt.
 *
 * We store text, not markup: nothing a provider sends can ever be rendered as HTML by us, which
 * is a stricter guarantee than sanitising and re-displaying it. The excerpt is capped because we
 * only keep enough to decide; the full offer stays one click away on the source.
 */
final class PlainText
{
    public const int MAX_LENGTH = 3000;

    public static function fromHtml(?string $html, int $maxLength = self::MAX_LENGTH): string
    {
        if (null === $html || '' === trim($html)) {
            return '';
        }

        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        // Block boundaries become line breaks before the tags go, so paragraphs survive.
        $text = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6]|/tr|/ul|/ol)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<\s*li\b[^>]*>#i', '• ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false === $space ? $cut : mb_substr($cut, 0, $space), " \n.,;:").' …';
    }
}
