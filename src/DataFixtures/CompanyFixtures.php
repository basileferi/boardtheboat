<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Company;
use App\Enum\CareerPageStatus;
use App\Enum\CompanySize;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

/**
 * entreprises du catalogue, générées avec faker. chaque entreprise est enregistrée comme
 * référence ("company-0", "company-1"...) pour que les offres puissent s'y rattacher.
 */
final class CompanyFixtures extends Fixture implements FixtureGroupInterface
{
    public const int COUNT = 15;

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        for ($i = 0; $i < self::COUNT; ++$i) {
            $company = new Company($faker->unique()->company());
            $company->setSourceReference('fixtures', 'company-'.$i);
            $company->setSector($faker->randomElement(self::sectors()));
            $company->setCity($faker->randomElement(['Bordeaux', 'Paris', 'Lyon', 'Nantes', 'Toulouse', 'Lille']));
            $company->setCountry('FR');
            $company->setSize($faker->randomElement(CompanySize::cases()));
            $company->setWebsite('https://'.$faker->unique()->domainName());
            $company->setSiren($faker->unique()->numerify('#########'));
            $company->markCareerPageChecked(CareerPageStatus::Valid);

            $manager->persist($company);
            $this->addReference('company-'.$i, $company);
        }

        $manager->flush();
    }

    /**
     * @return list<string>
     */
    public static function sectors(): array
    {
        return ['ESN', 'Logiciel', 'Fintech', 'E-commerce', 'Jeu vidéo', 'Santé numérique'];
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
