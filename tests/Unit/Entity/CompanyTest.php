<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use App\Entity\User;
use App\Enum\CompanySize;
use App\Enum\CompanyVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nameProvider(): iterable
    {
        yield 'legal form dropped' => ['SARL Dupont & Fils', 'dupont et fils'];
        yield 'accents folded' => ['Société Générale', 'societe generale'];
        yield 'punctuation collapsed' => ['Thales   -- Avionics!', 'thales avionics'];
        yield 'case folded' => ['CAPGEMINI', 'capgemini'];
        yield 'english suffix dropped' => ['Acme Ltd', 'acme'];
    }

    #[DataProvider('nameProvider')]
    public function testNamesAreNormalisedForDeduplication(string $raw, string $expected): void
    {
        self::assertSame($expected, Company::normalizeName($raw));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function websiteProvider(): iterable
    {
        yield 'https with www' => ['https://www.example.com/careers', 'example.com'];
        yield 'bare host' => ['example.fr', 'example.fr'];
        yield 'uppercase host' => ['HTTPS://EXAMPLE.COM', 'example.com'];
        yield 'subdomain kept' => ['https://jobs.example.com', 'jobs.example.com'];
        yield 'garbage' => ['not a url at all', null];
    }

    #[DataProvider('websiteProvider')]
    public function testDomainIsExtractedFromWebsite(string $website, ?string $expected): void
    {
        self::assertSame($expected, Company::extractDomain($website));
    }

    public function testAUserOwnedCompanyIsPrivateByDefault(): void
    {
        $company = new Company('Acme', new User('owner@example.test'));

        self::assertSame(CompanyVisibility::Private, $company->getVisibility());
        self::assertFalse($company->isGlobal());
    }

    public function testAnImportedCompanyGoesStraightToTheCatalogue(): void
    {
        $company = new Company('Acme');

        self::assertSame(CompanyVisibility::Catalogue, $company->getVisibility());
        self::assertTrue($company->isGlobal());
    }

    public function testFingerprintTracksTheFieldsThatDriveTheScore(): void
    {
        $company = new Company('Acme');
        $before = $company->getFingerprint();

        $company->setNotes('a private note');
        self::assertSame($before, $company->getFingerprint(), 'notes do not influence the score');

        $company->setSize(CompanySize::Medium);
        self::assertNotSame($before, $company->getFingerprint(), 'head count does influence the score');
    }
}
