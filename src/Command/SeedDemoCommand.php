<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Company;
use App\Entity\JobOffer;
use App\Enum\CareerPageStatus;
use App\Enum\CompanySize;
use App\Enum\ContractType;
use App\Enum\OfferStatus;
use App\Enum\WorkMode;
use App\Repository\CompanyRepository;
use App\Repository\JobOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills the database with plausible French companies and internship offers.
 *
 * This exists so the interface can be developed and demonstrated without API keys, and so the
 * relevance scorer has something to rank. It is idempotent: rows are upserted on
 * (source, externalId) exactly like the real ingestion handlers, so running it twice changes
 * nothing. The "fixtures" source marks the rows as seeded, which makes them easy to drop.
 */
#[AsCommand(
    name: 'app:seed:demo',
    description: 'Create realistic demo companies and offers so the interface is usable without API keys.',
)]
final class SeedDemoCommand extends Command
{
    private const string SOURCE = 'fixtures';

    /** @var list<array{name: string, sector: string, naf: string, city: string, size: CompanySize, domain: string}> */
    private const array COMPANIES = [
        ['name' => 'Cdiscount', 'sector' => 'E-commerce', 'naf' => '47.91A', 'city' => 'Bordeaux', 'size' => CompanySize::Large, 'domain' => 'cdiscount.example'],
        ['name' => 'Ubisoft', 'sector' => 'Jeu vidéo', 'naf' => '58.21Z', 'city' => 'Montpellier', 'size' => CompanySize::Large, 'domain' => 'ubisoft.example'],
        ['name' => 'Doctolib', 'sector' => 'Santé numérique', 'naf' => '62.01Z', 'city' => 'Paris', 'size' => CompanySize::Large, 'domain' => 'doctolib.example'],
        ['name' => 'Blablacar', 'sector' => 'Mobilité', 'naf' => '62.01Z', 'city' => 'Paris', 'size' => CompanySize::Medium, 'domain' => 'blablacar.example'],
        ['name' => 'Qonto', 'sector' => 'Fintech', 'naf' => '64.19Z', 'city' => 'Paris', 'size' => CompanySize::Medium, 'domain' => 'qonto.example'],
        ['name' => 'Thales Avionics', 'sector' => 'Aéronautique', 'naf' => '26.51B', 'city' => 'Mérignac', 'size' => CompanySize::VeryLarge, 'domain' => 'thales-avionics.example'],
        ['name' => 'Dassault Systèmes', 'sector' => 'Logiciel industriel', 'naf' => '58.29A', 'city' => 'Vélizy', 'size' => CompanySize::VeryLarge, 'domain' => 'dassault-systemes.example'],
        ['name' => 'OVHcloud', 'sector' => 'Hébergement', 'naf' => '63.11Z', 'city' => 'Roubaix', 'size' => CompanySize::Large, 'domain' => 'ovhcloud.example'],
        ['name' => 'Scaleway', 'sector' => 'Cloud', 'naf' => '63.11Z', 'city' => 'Paris', 'size' => CompanySize::Medium, 'domain' => 'scaleway.example'],
        ['name' => 'Talend', 'sector' => 'Données', 'naf' => '58.29A', 'city' => 'Nantes', 'size' => CompanySize::Medium, 'domain' => 'talend.example'],
        ['name' => 'Younited Credit', 'sector' => 'Fintech', 'naf' => '64.92Z', 'city' => 'Paris', 'size' => CompanySize::Medium, 'domain' => 'younited.example'],
        ['name' => 'Ledger', 'sector' => 'Sécurité', 'naf' => '26.20Z', 'city' => 'Vierzon', 'size' => CompanySize::Medium, 'domain' => 'ledger.example'],
        ['name' => 'Alan', 'sector' => 'Assurance santé', 'naf' => '65.12Z', 'city' => 'Paris', 'size' => CompanySize::Medium, 'domain' => 'alan.example'],
        ['name' => 'Back Market', 'sector' => 'E-commerce', 'naf' => '47.91A', 'city' => 'Bordeaux', 'size' => CompanySize::Medium, 'domain' => 'backmarket.example'],
        ['name' => 'Manomano', 'sector' => 'E-commerce', 'naf' => '47.91A', 'city' => 'Paris', 'size' => CompanySize::Medium, 'domain' => 'manomano.example'],
        ['name' => 'Lucca', 'sector' => 'Logiciel RH', 'naf' => '58.29A', 'city' => 'Nantes', 'size' => CompanySize::Small, 'domain' => 'lucca.example'],
        ['name' => 'Wiztopic', 'sector' => 'Communication', 'naf' => '62.01Z', 'city' => 'Paris', 'size' => CompanySize::Small, 'domain' => 'wiztopic.example'],
        ['name' => 'Agicap', 'sector' => 'Fintech', 'naf' => '62.01Z', 'city' => 'Lyon', 'size' => CompanySize::Medium, 'domain' => 'agicap.example'],
        ['name' => 'Ynov Campus', 'sector' => 'Formation', 'naf' => '85.42Z', 'city' => 'Bordeaux', 'size' => CompanySize::Medium, 'domain' => 'ynov.example'],
        ['name' => 'Betclic', 'sector' => 'Divertissement', 'naf' => '92.00Z', 'city' => 'Bordeaux', 'size' => CompanySize::Medium, 'domain' => 'betclic.example'],
        ['name' => 'Aquitaine Digital', 'sector' => 'ESN', 'naf' => '62.02A', 'city' => 'Pessac', 'size' => CompanySize::Small, 'domain' => 'aquitaine-digital.example'],
        ['name' => 'Atlantic Software', 'sector' => 'ESN', 'naf' => '62.01Z', 'city' => 'La Rochelle', 'size' => CompanySize::Small, 'domain' => 'atlantic-software.example'],
        ['name' => 'Garonne Data', 'sector' => 'Données', 'naf' => '62.01Z', 'city' => 'Toulouse', 'size' => CompanySize::Small, 'domain' => 'garonne-data.example'],
        ['name' => 'Novapole', 'sector' => 'Énergie', 'naf' => '35.11Z', 'city' => 'Nantes', 'size' => CompanySize::Medium, 'domain' => 'novapole.example'],
        ['name' => 'Métropole Santé', 'sector' => 'Santé', 'naf' => '86.10Z', 'city' => 'Lille', 'size' => CompanySize::Large, 'domain' => 'metropole-sante.example'],
    ];

    /** @var list<array{name: string, sector: string, city: string, country: string, domain: string}> */
    private const array INTERNATIONAL_COMPANIES = [
        ['name' => 'Spotify', 'sector' => 'Musique', 'city' => 'Stockholm', 'country' => 'SE', 'domain' => 'spotify.example'],
        ['name' => 'Zalando', 'sector' => 'E-commerce', 'city' => 'Berlin', 'country' => 'DE', 'domain' => 'zalando.example'],
        ['name' => 'Adyen', 'sector' => 'Paiement', 'city' => 'Amsterdam', 'country' => 'NL', 'domain' => 'adyen.example'],
        ['name' => 'Monzo', 'sector' => 'Fintech', 'city' => 'Londres', 'country' => 'GB', 'domain' => 'monzo.example'],
        ['name' => 'Remote Nomads', 'sector' => 'Logiciel', 'city' => 'Lisbonne', 'country' => 'PT', 'domain' => 'remotenomads.example'],
    ];

    /** @var list<array{title: string, keywords: string}> */
    private const array ROLES = [
        ['title' => 'Stage développeur back-end PHP / Symfony', 'keywords' => 'PHP 8, Symfony, Doctrine, MySQL, tests PHPUnit, API REST'],
        ['title' => 'Alternance développeur full-stack', 'keywords' => 'Symfony, Twig, JavaScript, Stimulus, CSS moderne, accessibilité'],
        ['title' => 'Stage développeur front-end', 'keywords' => 'HTML sémantique, CSS, TypeScript, design system, performance web'],
        ['title' => 'Alternance ingénieur données', 'keywords' => 'SQL, Python, ETL, dataviz, qualité de données'],
        ['title' => 'Stage DevOps / plateforme', 'keywords' => 'Docker, CI/CD, Linux, observabilité, infrastructure as code'],
        ['title' => 'Stage QA et automatisation des tests', 'keywords' => 'tests end-to-end, PHPUnit, Playwright, intégration continue'],
        ['title' => 'Alternance développeur API', 'keywords' => 'API Platform, REST, OpenAPI, sécurité applicative'],
        ['title' => 'Stage cybersécurité applicative', 'keywords' => 'OWASP, revue de code, durcissement, gestion des secrets'],
        ['title' => 'Alternance chef de projet technique', 'keywords' => 'agile, rédaction de specs, coordination, suivi de production'],
        ['title' => 'Stage développeur mobile', 'keywords' => 'Kotlin, Swift, API REST, tests, accessibilité mobile'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyRepository $companies,
        private readonly JobOfferRepository $offers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('offers', null, InputOption::VALUE_REQUIRED, 'How many offers to create', '200');
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Delete every demo row instead (once app:jobs:refresh has loaded real data)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('purge')) {
            // Offers first; the database cascades scores and applications attached to demo companies.
            $offers = $this->offers->deleteBySource(self::SOURCE);
            $companies = $this->companies->deleteBySource(self::SOURCE);
            $io->success(\sprintf('%d demo offers and %d demo companies deleted.', $offers, $companies));

            return Command::SUCCESS;
        }

        $wanted = max(1, (int) $input->getOption('offers'));

        $companies = $this->seedCompanies();
        $io->text(\sprintf('%d companies available.', \count($companies)));

        $created = 0;
        $updated = 0;

        for ($index = 0; $index < $wanted; ++$index) {
            $company = $companies[$index % \count($companies)];
            $role = self::ROLES[$index % \count(self::ROLES)];
            $externalId = \sprintf('demo-%03d', $index);

            $offer = $this->offers->findOneBySource(self::SOURCE, $externalId);
            if (null === $offer) {
                $offer = new JobOffer(
                    $company,
                    self::SOURCE,
                    $externalId,
                    $role['title'],
                    \sprintf('https://%s/jobs/%s', (string) $company->getDomain(), $externalId),
                );
                $this->entityManager->persist($offer);
                ++$created;
            } else {
                ++$updated;
            }

            $offer->setCompany($company);
            $offer->setTitle($role['title']);
            $offer->setDescription($this->describe($company, $role));
            $offer->setLocation($company->getCity());
            $offer->setCountry($company->getCountry());
            $offer->setContractType(0 === $index % 3 ? ContractType::Apprenticeship : ContractType::Internship);
            $offer->setWorkMode(match ($index % 4) {
                0 => WorkMode::Remote,
                1 => WorkMode::Hybrid,
                default => WorkMode::Onsite,
            });
            $offer->setStatus(0 === $index % 17 ? OfferStatus::Closed : OfferStatus::Open);
            $offer->setPublishedAt(new \DateTimeImmutable(\sprintf('-%d days', $index % 90)));
            $offer->setSalaryText(0 === $index % 5 ? 'Gratification légale + tickets restaurant' : null);

            if (0 === $index % 25) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $io->success(\sprintf('%d offers created, %d refreshed.', $created, $updated));

        return Command::SUCCESS;
    }

    /**
     * @return list<Company>
     */
    private function seedCompanies(): array
    {
        $companies = [];

        foreach (self::COMPANIES as $index => $row) {
            $company = $this->upsertCompany(\sprintf('fr-%02d', $index), $row['name'], $row['domain']);
            $company->setSector($row['sector']);
            $company->setNafCode($row['naf']);
            $company->setCity($row['city']);
            $company->setCountry('FR');
            $company->setSize($row['size']);
            $company->setSiren(\sprintf('%09d', 100000000 + $index));
            $companies[] = $company;
        }

        foreach (self::INTERNATIONAL_COMPANIES as $index => $row) {
            $company = $this->upsertCompany(\sprintf('int-%02d', $index), $row['name'], $row['domain']);
            $company->setSector($row['sector']);
            $company->setCity($row['city']);
            $company->setCountry($row['country']);
            $company->setSize(CompanySize::Large);
            $companies[] = $company;
        }

        $this->entityManager->flush();

        return $companies;
    }

    private function upsertCompany(string $externalId, string $name, string $domain): Company
    {
        $company = $this->companies->findOneBySource(self::SOURCE, $externalId);

        if (null === $company) {
            $company = new Company($name);
            $company->setSourceReference(self::SOURCE, $externalId);
            $this->entityManager->persist($company);
        }

        $company->setName($name);
        $company->setWebsite('https://'.$domain);
        $company->setCareerUrl('https://'.$domain.'/carrieres');
        $company->markCareerPageChecked(CareerPageStatus::Valid);

        return $company;
    }

    /**
     * @param array{title: string, keywords: string} $role
     */
    private function describe(Company $company, array $role): string
    {
        return \sprintf(
            "%s recherche un profil pour un %s à %s.\n\n".
            "Environnement technique : %s.\n\n".
            'Vous rejoindrez une équipe du secteur %s. Les missions couvrent la conception, le développement et la mise en production, '.
            "avec une revue de code systématique et un accompagnement par un référent technique.\n\n".
            'Profil recherché : étudiant en informatique, curieux, à l\'aise en autonomie et en travail collectif.',
            $company->getName(),
            mb_strtolower($role['title']),
            (string) $company->getCity(),
            $role['keywords'],
            mb_strtolower((string) $company->getSector()),
        );
    }
}
