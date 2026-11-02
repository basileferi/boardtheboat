<?php

declare(strict_types=1);

namespace App\Service\Matching;

use App\Dto\ScoreBreakdown;
use App\Entity\Company;
use App\Entity\Profile;
use App\Enum\CareerPageStatus;
use App\Enum\CompanySize;
use App\Enum\Region;

/**
 * Scores one company against one profile, and explains itself.
 *
 * Deliberately pure: no database, no clock, no randomness. The pipeline persists the result,
 * the UI displays the breakdown, and the tests can pin every rule.
 *
 * The four criteria are weighted so that "what I want to do" (keywords, sector) outweighs
 * "where" and "is it reachable", which are tie-breakers rather than reasons to apply.
 */
final readonly class RelevanceScorer
{
    public const int WEIGHT_KEYWORDS = 35;
    public const int WEIGHT_SECTOR = 25;
    public const int WEIGHT_LOCATION = 25;
    public const int WEIGHT_REACHABILITY = 15;

    public function score(Profile $profile, Company $company): ScoreBreakdown
    {
        $keywords = $this->scoreKeywords($profile, $company);
        $sector = $this->scoreSector($profile, $company);
        $location = $this->scoreLocation($profile, $company);
        $reachability = $this->scoreReachability($company);

        $breakdown = [
            'keywords' => $keywords,
            'sector' => $sector,
            'location' => $location,
            'reachability' => $reachability,
        ];

        return new ScoreBreakdown(
            array_sum($breakdown),
            $breakdown,
            $this->explain($breakdown),
        );
    }

    /**
     * Share of the profile's keywords and target roles that appear in what we know about the
     * company. A profile with no keywords scores neutral rather than zero: an empty profile
     * must not push every company to the bottom.
     */
    private function scoreKeywords(Profile $profile, Company $company): int
    {
        $needles = $this->normalizeAll([...$profile->getKeywords(), ...$profile->getTargetRoles(), ...$profile->getSkills()]);
        if ([] === $needles) {
            return intdiv(self::WEIGHT_KEYWORDS, 2);
        }

        $haystack = $this->normalize(implode(' ', array_filter([
            $company->getName(),
            $company->getSector(),
            $company->getNafCode(),
            $company->getWhyInteresting(),
        ])));

        $hits = 0;
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                ++$hits;
            }
        }

        return (int) round(self::WEIGHT_KEYWORDS * min(1.0, $hits / min(\count($needles), 4)));
    }

    private function scoreSector(Profile $profile, Company $company): int
    {
        $wanted = $this->normalizeAll($profile->getSectors());
        $actual = $this->normalize((string) $company->getSector());

        if ([] === $wanted) {
            return intdiv(self::WEIGHT_SECTOR, 2);
        }

        if ('' === $actual) {
            return 0;
        }

        foreach ($wanted as $sector) {
            if ($sector === $actual) {
                return self::WEIGHT_SECTOR;
            }

            if (str_contains($actual, $sector) || str_contains($sector, $actual)) {
                return (int) round(self::WEIGHT_SECTOR * 0.7);
            }
        }

        return 0;
    }

    /**
     * A named city the candidate wants scores full marks; otherwise being on the right side of
     * the France / International switch is worth half, because a spontaneous application can
     * still make sense elsewhere in the same area.
     */
    private function scoreLocation(Profile $profile, Company $company): int
    {
        $city = $this->normalize((string) $company->getCity());
        $wanted = $this->normalizeAll($profile->getPreferredLocations());
        $inFrance = 'FR' === $company->getCountry();
        $regionMatches = Region::France === $profile->getDefaultRegion() ? $inFrance : !$inFrance;

        if (!$regionMatches) {
            return 0;
        }

        if ([] === $wanted) {
            return intdiv(self::WEIGHT_LOCATION, 2);
        }

        foreach ($wanted as $preferred) {
            if ('' !== $city && (str_contains($city, $preferred) || str_contains($preferred, $city))) {
                return self::WEIGHT_LOCATION;
            }
        }

        return intdiv(self::WEIGHT_LOCATION, 3);
    }

    /**
     * How likely we are to be able to write to them at all: a validated career page is the
     * whole point of a spontaneous application, a blocked one is a dead end.
     */
    private function scoreReachability(Company $company): int
    {
        $base = match ($company->getCareerPageStatus()) {
            CareerPageStatus::Valid => self::WEIGHT_REACHABILITY,
            CareerPageStatus::Pending => intdiv(self::WEIGHT_REACHABILITY, 3),
            CareerPageStatus::Invalid, CareerPageStatus::Blocked => 0,
        };

        // Very large employers run structured campaigns; a smaller one is likelier to read a
        // spontaneous application, so it keeps its full reachability score.
        return CompanySize::VeryLarge === $company->getSize() ? intdiv($base, 2) : $base;
    }

    /**
     * Translation keys, in the order they should be shown. The UI never invents its own
     * wording for a score it did not compute.
     *
     * @param array<string, int> $breakdown
     *
     * @return list<string>
     */
    private function explain(array $breakdown): array
    {
        $reasons = [];

        foreach ($breakdown as $criterion => $points) {
            $level = match (true) {
                0 === $points => 'none',
                $points >= $this->weightOf($criterion) => 'full',
                default => 'partial',
            };

            $reasons[] = \sprintf('relevance.reason.%s.%s', $criterion, $level);
        }

        return $reasons;
    }

    private function weightOf(string $criterion): int
    {
        return match ($criterion) {
            'keywords' => self::WEIGHT_KEYWORDS,
            'sector' => self::WEIGHT_SECTOR,
            'location' => self::WEIGHT_LOCATION,
            default => self::WEIGHT_REACHABILITY,
        };
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function normalizeAll(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $clean = $this->normalize($value);
            if ('' !== $clean) {
                $normalized[] = $clean;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalize(string $value): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
        $ascii = \is_string($ascii) ? $ascii : mb_strtolower($value);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '') ?? '');
    }
}
