<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

use Symfony\Component\Intl\Countries;

/**
 * Reads an ISO 3166 alpha-2 code out of the free-text locations job boards publish
 * ("Paris, France", "Berlin", "Austin, TX", "Remote / Düsseldorf").
 *
 * Country names come from symfony/intl in English, French and German, so the only list kept
 * here is the handful of aliases and big cities that providers write without a country.
 */
final class CountryResolver
{
    private const array US_STATES = ['AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'DC'];

    private const array ALIASES = [
        'usa' => 'US', 'us' => 'US', 'united states of america' => 'US', 'uk' => 'GB', 'england' => 'GB',
        'scotland' => 'GB', 'deutschland' => 'DE', 'the netherlands' => 'NL', 'holland' => 'NL',
        'czech republic' => 'CZ', 'south korea' => 'KR', 'russia' => 'RU', 'türkiye' => 'TR', 'turkey' => 'TR',
        'vietnam' => 'VN', 'uae' => 'AE',
        // Cities the boards regularly publish on their own.
        'paris' => 'FR', 'lyon' => 'FR', 'bordeaux' => 'FR', 'toulouse' => 'FR', 'nantes' => 'FR', 'lille' => 'FR',
        'marseille' => 'FR', 'montpellier' => 'FR', 'rennes' => 'FR', 'strasbourg' => 'FR', 'nice' => 'FR',
        'berlin' => 'DE', 'munich' => 'DE', 'münchen' => 'DE', 'hamburg' => 'DE', 'cologne' => 'DE', 'köln' => 'DE',
        'frankfurt' => 'DE', 'düsseldorf' => 'DE', 'stuttgart' => 'DE', 'nuremberg' => 'DE', 'nürnberg' => 'DE',
        'essen' => 'DE', 'leipzig' => 'DE', 'dresden' => 'DE', 'hannover' => 'DE', 'bremen' => 'DE', 'garching bei münchen' => 'DE',
        'london' => 'GB', 'dublin' => 'IE', 'amsterdam' => 'NL', 'madrid' => 'ES', 'barcelona' => 'ES',
        'lisbon' => 'PT', 'lisboa' => 'PT', 'brussels' => 'BE', 'bruxelles' => 'BE', 'geneva' => 'CH', 'genève' => 'CH',
        'zurich' => 'CH', 'zürich' => 'CH', 'vienna' => 'AT', 'wien' => 'AT', 'milan' => 'IT', 'milano' => 'IT',
        'singapore' => 'SG', 'montreal' => 'CA', 'montréal' => 'CA', 'toronto' => 'CA',
    ];

    /** @var array<string, string>|null lowercase country name => alpha-2 */
    private ?array $names = null;

    public function resolve(?string $location): ?string
    {
        if (null === $location || '' === trim($location)) {
            return null;
        }

        // "Austin, TX": a trailing two-letter US state.
        if (1 === preg_match('/,\s*([A-Z]{2})\s*$/', trim($location), $match) && \in_array($match[1], self::US_STATES, true)) {
            return 'US';
        }

        $names = $this->names();
        $parts = preg_split('/\s*[,\/|(–]\s*|\s*\)\s*|\s+-\s+/u', mb_strtolower(trim($location)));

        foreach (array_reverse(false === $parts ? [] : $parts) as $part) {
            $part = trim($part, " .\t");
            if ('' === $part) {
                continue;
            }
            if (isset($names[$part])) {
                return $names[$part];
            }
            if (isset(self::ALIASES[$part])) {
                return self::ALIASES[$part];
            }
        }

        return null;
    }

    public function isRemote(?string $location): bool
    {
        return null !== $location
            && 1 === preg_match('/\b(remote|flexible|anywhere|t[eé]l[eé]travail|homeoffice|home office|worldwide)\b/iu', $location);
    }

    /**
     * @return array<string, string>
     */
    private function names(): array
    {
        if (null !== $this->names) {
            return $this->names;
        }

        $names = [];
        foreach (['en', 'fr', 'de'] as $locale) {
            foreach (Countries::getNames($locale) as $code => $name) {
                $names[mb_strtolower($name)] = $code;
            }
        }

        return $this->names = $names;
    }
}
