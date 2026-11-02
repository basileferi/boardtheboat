<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Company;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\CareerPageStatus;
use App\Enum\CompanySize;
use App\Enum\Region;
use App\Service\Matching\RelevanceScorer;
use PHPUnit\Framework\TestCase;

/**
 * The scorer decides what the user sees first, so each rule is pinned separately and the
 * explanation is checked alongside the number: a score nobody can justify is not usable.
 */
final class RelevanceScorerTest extends TestCase
{
    private RelevanceScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new RelevanceScorer();
    }

    public function testAPerfectMatchScoresNearlyFullMarks(): void
    {
        $profile = $this->profile(
            keywords: ['symfony', 'php'],
            sectors: ['ESN'],
            locations: ['Bordeaux'],
        );

        $company = $this->company('Aquitaine PHP & Symfony', sector: 'ESN', city: 'Bordeaux');

        $result = $this->scorer->score($profile, $company);

        self::assertSame(100, $result->score);
        self::assertSame('high', $result->band());
        self::assertContains('relevance.reason.keywords.full', $result->reasons);
        self::assertContains('relevance.reason.location.full', $result->reasons);
    }

    public function testACompanyOnTheWrongSideOfTheRegionSwitchLosesAllLocationPoints(): void
    {
        $profile = $this->profile(locations: ['Bordeaux'], region: Region::France);
        $company = $this->company('Remote Nomads', city: 'Lisbonne', country: 'PT');

        $result = $this->scorer->score($profile, $company);

        self::assertSame(0, $result->criteria['location']);
        self::assertContains('relevance.reason.location.none', $result->reasons);
    }

    public function testTheSameCompanyScoresOnLocationOnceTheProfileLooksAbroad(): void
    {
        $company = $this->company('Remote Nomads', city: 'Lisbonne', country: 'PT');

        $french = $this->scorer->score($this->profile(region: Region::France), $company);
        $international = $this->scorer->score($this->profile(region: Region::International), $company);

        self::assertGreaterThan($french->criteria['location'], $international->criteria['location']);
    }

    public function testAnUnreachableCareerPageCostsItsWholeCriterion(): void
    {
        $profile = $this->profile();

        $reachable = $this->company('Reachable', careerPageStatus: CareerPageStatus::Valid);
        $blocked = $this->company('Blocked', careerPageStatus: CareerPageStatus::Blocked);

        self::assertSame(RelevanceScorer::WEIGHT_REACHABILITY, $this->scorer->score($profile, $reachable)->criteria['reachability']);
        self::assertSame(0, $this->scorer->score($profile, $blocked)->criteria['reachability']);
    }

    public function testAVeryLargeEmployerIsRankedBelowASmallerOneAllElseBeingEqual(): void
    {
        $profile = $this->profile();

        $small = $this->company('Petite boîte', size: CompanySize::Small);
        $huge = $this->company('Très grand groupe', size: CompanySize::VeryLarge);

        self::assertGreaterThan(
            $this->scorer->score($profile, $huge)->score,
            $this->scorer->score($profile, $small)->score,
            'a spontaneous application is likelier to be read by a smaller employer',
        );
    }

    public function testAnEmptyProfileStillRanksCompaniesInsteadOfZeroingThemAll(): void
    {
        $result = $this->scorer->score($this->profile(), $this->company('Anything'));

        self::assertGreaterThan(0, $result->score, 'an empty profile must not flatten the whole catalogue');
        self::assertLessThan(100, $result->score);
    }

    public function testANearbySectorScoresLessThanAnExactOne(): void
    {
        $exact = $this->scorer->score(
            $this->profile(sectors: ['Fintech']),
            $this->company('A', sector: 'Fintech'),
        );

        $nearby = $this->scorer->score(
            $this->profile(sectors: ['Fintech']),
            $this->company('B', sector: 'Fintech et assurance'),
        );

        $unrelated = $this->scorer->score(
            $this->profile(sectors: ['Fintech']),
            $this->company('C', sector: 'Agroalimentaire'),
        );

        self::assertGreaterThan($nearby->criteria['sector'], $exact->criteria['sector']);
        self::assertGreaterThan($unrelated->criteria['sector'], $nearby->criteria['sector']);
        self::assertSame(0, $unrelated->criteria['sector']);
    }

    public function testTheBreakdownAlwaysSumsToTheScore(): void
    {
        $result = $this->scorer->score(
            $this->profile(keywords: ['php'], sectors: ['ESN'], locations: ['Lyon']),
            $this->company('Lyon PHP', sector: 'ESN', city: 'Lyon'),
        );

        self::assertSame($result->score, array_sum($result->criteria));
        self::assertCount(4, $result->reasons, 'every criterion is explained, including the ones that scored zero');
    }

    /**
     * @param list<string> $keywords
     * @param list<string> $sectors
     * @param list<string> $locations
     */
    private function profile(array $keywords = [], array $sectors = [], array $locations = [], Region $region = Region::France): Profile
    {
        $profile = new Profile(new User('candidate@example.test'));
        $profile->setKeywords($keywords);
        $profile->setSectors($sectors);
        $profile->setPreferredLocations($locations);
        $profile->setDefaultRegion($region);

        return $profile;
    }

    private function company(
        string $name,
        ?string $sector = null,
        ?string $city = null,
        string $country = 'FR',
        CompanySize $size = CompanySize::Medium,
        CareerPageStatus $careerPageStatus = CareerPageStatus::Valid,
    ): Company {
        $company = new Company($name);
        $company->setSector($sector);
        $company->setCity($city);
        $company->setCountry($country);
        $company->setSize($size);
        $company->markCareerPageChecked($careerPageStatus);

        return $company;
    }
}
