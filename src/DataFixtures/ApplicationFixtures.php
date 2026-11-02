<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Application;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\PipelineStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * quelques candidatures pour le compte démo, dont une envoyée il y a 10 jours : elle apparaît
 * donc direct dans "à relancer", ce qui permet de montrer les relances sans attendre une semaine.
 */
final class ApplicationFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function load(ObjectManager $manager): void
    {
        $demo = $this->getReference(UserFixtures::DEMO, User::class);
        $delay = $demo->getProfile()?->getRelanceDelayDays() ?? 7;

        $statuses = [PipelineStatus::Saved, PipelineStatus::ToApply, PipelineStatus::Applied, PipelineStatus::Interview];

        foreach ($statuses as $index => $status) {
            $offer = $this->getReference('offer-'.$index, JobOffer::class);
            $application = new Application($demo, $offer->getCompany(), $offer);
            $application->setTargetRole($offer->getTitle());

            // "envoyée il y a 10 jours" pour que la relance soit déjà due
            $application->changeStatus($status, $delay, new \DateTimeImmutable('-10 days'));

            $manager->persist($application);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class, JobOfferFixtures::class];
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
