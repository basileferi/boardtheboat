<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Region;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * comptes de démo : deux comptes "en dur" pour se connecter tout de suite, puis 10 faux
 * utilisateurs générés avec faker.
 *
 * le mot de passe des comptes en dur respecte la règle des 12 caractères minimum.
 */
final class UserFixtures extends Fixture implements FixtureGroupInterface
{
    public const string ADMIN = 'user-admin';
    public const string DEMO = 'user-demo';
    public const string PASSWORD = 'boardtheboat';

    private Generator $faker;

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
        $this->faker = Factory::create('fr_FR');
    }

    public function load(ObjectManager $manager): void
    {
        $admin = $this->account('admin@test.fr', [User::ROLE_ADMIN]);
        $demo = $this->account('user@test.fr');

        // le compte démo a un profil rempli, sinon le classement n'a rien à comparer
        $profile = $demo->getProfile();
        if (null !== $profile) {
            $profile->setTargetRoles(['développeur back-end', 'développeur full-stack']);
            $profile->setKeywords(['php', 'symfony', 'api']);
            $profile->setSectors(['ESN', 'Logiciel']);
            $profile->setPreferredLocations(['Bordeaux', 'Paris']);
            $profile->setDefaultRegion(Region::France);
        }

        foreach ([$admin, $demo] as $user) {
            $manager->persist($user);
        }

        $this->addReference(self::ADMIN, $admin);
        $this->addReference(self::DEMO, $demo);

        for ($i = 0; $i < 10; ++$i) {
            $manager->persist($this->account($this->faker->unique()->safeEmail()));
        }

        $manager->flush();
    }

    /**
     * @param list<string> $roles
     */
    private function account(string $email, array $roles = []): User
    {
        $user = new User($email);
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $user->setRoles($roles);
        $user->setVerified(true);

        // le profil est en cascade persist depuis l'utilisateur
        new Profile($user);

        return $user;
    }

    /**
     * "users" = seulement les comptes (sans toucher aux vraies offres), "demo" = tout le jeu.
     *
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['users', 'demo'];
    }
}
