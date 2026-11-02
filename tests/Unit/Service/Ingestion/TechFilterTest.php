<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Ingestion;

use App\Enum\ContractType;
use App\Service\Ingestion\CountryResolver;
use App\Service\Ingestion\PlainText;
use App\Service\Ingestion\TechFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TechFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function titles(): iterable
    {
        yield 'software intern' => ['Software Engineering Intern (Summer 2027)', true];
        yield 'french stage' => ['Stage développeur PHP / Symfony', true];
        yield 'data apprenticeship' => ['Alternance Data Engineer', true];
        yield 'german working student' => ['Werkstudent IT Support (m/w/d)', true];
        yield 'fachinformatiker' => ['Fachinformatikerin (m/w/d) - Systemintegration', true];
        yield 'devops' => ['DevOps Intern', true];
        yield 'sales intern' => ['Sales & Business Development Intern', false];
        yield 'social media' => ['Praktikum Social Media & Content Creation', false];
        yield 'civil engineering' => ['Ingénieur(e) organisation / qualité - STAGE', false];
        yield 'facades' => ['Ingénieur.e Façades - Alternance', false];
        yield 'hr' => ['Human Resources Assistant Intern', false];
        yield 'strategy' => ['Strategy Analyst Project Intern', false];
    }

    #[DataProvider('titles')]
    public function testTechRolesAreKeptAndOthersDropped(string $title, bool $expected): void
    {
        self::assertSame($expected, TechFilter::isTech($title));
    }

    public function testProviderTagsCanQualifyATitle(): void
    {
        self::assertTrue(TechFilter::isTech('Junior intern', ['Software-Development']));
        self::assertFalse(TechFilter::isTech('Marketing intern', ['Software-Development']), 'an excluded title wins over tags');
    }

    public function testContractTypeIsReadFromTitleAndTags(): void
    {
        self::assertSame(ContractType::Apprenticeship, TechFilter::contractType('Alternance développeur web'));
        self::assertSame(ContractType::Apprenticeship, TechFilter::contractType('Fachinformatiker', ['apprenticeship']));
        self::assertSame(ContractType::Internship, TechFilter::contractType('Stage data'));
        self::assertSame(ContractType::Internship, TechFilter::contractType('Developer', ['Working student']));
        self::assertNull(TechFilter::contractType('Senior Backend Engineer', ['Full-time']));
    }

    public function testCountryIsResolvedFromFreeTextLocations(): void
    {
        $resolver = new CountryResolver();

        self::assertSame('FR', $resolver->resolve('Paris, France'));
        self::assertSame('FR', $resolver->resolve('Bordeaux'));
        self::assertSame('US', $resolver->resolve('Austin, TX'));
        self::assertSame('DE', $resolver->resolve('Remote / Düsseldorf'));
        self::assertSame('GB', $resolver->resolve('London, United Kingdom'));
        self::assertSame('DE', $resolver->resolve('Deutschland'));
        self::assertNull($resolver->resolve('Flexible / Remote'));
        self::assertNull($resolver->resolve(null));
        self::assertTrue($resolver->isRemote('Flexible / Remote'));
        self::assertFalse($resolver->isRemote('Lyon, France'));
    }

    public function testHtmlBecomesAShortPlainTextExcerpt(): void
    {
        $text = PlainText::fromHtml('<h3>Role</h3><p>Build &amp; ship.</p><ul><li>PHP</li><li>SQL</li></ul><script>alert(1)</script>');

        self::assertSame("Role\nBuild & ship.\n• PHP\n• SQL", $text);
        self::assertSame('', PlainText::fromHtml(null));

        $long = PlainText::fromHtml(str_repeat('word ', 2000), 50);
        self::assertLessThanOrEqual(52, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }
}
