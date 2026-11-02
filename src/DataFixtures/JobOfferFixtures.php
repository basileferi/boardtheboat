<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\JobOffer;
use App\Enum\ContractType;
use App\Enum\WorkMode;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

/**
 * 40 offres de stage / alternance. elles dépendent des entreprises : DependentFixtureInterface
 * garantit que CompanyFixtures passe avant, et getReference() récupère une entreprise au hasard.
 */
final class JobOfferFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');
        $titles = ['Stage développeur PHP / Symfony', 'Alternance développeur full-stack', 'Stage développeur front-end', 'Stage DevOps', 'Alternance data engineer'];

        for ($i = 0; $i < 40; ++$i) {
            $company = $this->getReference('company-'.$faker->numberBetween(0, CompanyFixtures::COUNT - 1), Company::class);

            $offer = new JobOffer($company, 'fixtures', 'offer-'.$i, $faker->randomElement($titles), 'https://example.test/offres/'.$i);
            $offer->setDescription($faker->realText(600));
            $offer->setLocation($company->getCity());
            $offer->setCountry('FR');
            $offer->setContractType($faker->randomElement([ContractType::Internship, ContractType::Apprenticeship]));
            $offer->setWorkMode($faker->randomElement([WorkMode::Onsite, WorkMode::Hybrid, WorkMode::Remote]));
            $offer->setPublishedAt(\DateTimeImmutable::createFromMutable($faker->dateTimeBetween('-60 days')));

            $manager->persist($offer);
            $this->addReference('offer-'.$i, $offer);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [CompanyFixtures::class];
    }

    /**
     * "users" = seulement les comptes (sans toucher aux vraies offres), "demo" = tout le jeu.
     *
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['demo'];
    }
}
