<?php

declare(strict_types=1);

namespace App\Service\Matching;

use App\Entity\Company;
use App\Entity\Profile;
use App\Entity\RelevanceScore;
use App\Repository\CompanyRepository;
use App\Repository\RelevanceScoreRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists what the RelevanceScorer computes, and only what changed.
 *
 * A pair (user, company) is rescored when either fingerprint moved. On an unchanged profile a
 * nightly run therefore touches nothing, which is what keeps it affordable on shared hosting.
 */
final readonly class CompanyRanker
{
    public function __construct(
        private RelevanceScorer $scorer,
        private CompanyRepository $companies,
        private RelevanceScoreRepository $scores,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int the number of scores actually written
     */
    public function rankAllFor(Profile $profile, int $batchSize = 100): int
    {
        $user = $profile->getUser();
        $existing = $this->scores->indexedByCompanyIdFor($user);
        $written = 0;

        foreach ($this->companies->findVisibleTo($user, 10000) as $index => $company) {
            $companyId = (string) $company->getId();
            $current = $existing[$companyId] ?? null;

            if (null !== $current && !$current->isStaleFor($profile->getFingerprint(), $company->getFingerprint())) {
                continue;
            }

            $this->write($profile, $company, $current);
            ++$written;

            if (0 === ($index + 1) % $batchSize) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        return $written;
    }

    public function rankOne(Profile $profile, Company $company): RelevanceScore
    {
        $existing = $this->scores->findOneFor($profile->getUser(), $company);
        $score = $this->write($profile, $company, $existing);
        $this->entityManager->flush();

        return $score;
    }

    private function write(Profile $profile, Company $company, ?RelevanceScore $existing): RelevanceScore
    {
        $breakdown = $this->scorer->score($profile, $company);

        if (null === $existing) {
            $existing = new RelevanceScore(
                $profile->getUser(),
                $company,
                $breakdown->score,
                $breakdown->criteria,
                $breakdown->reasons,
                $profile->getFingerprint(),
                $company->getFingerprint(),
            );
            $this->entityManager->persist($existing);

            return $existing;
        }

        $existing->update(
            $breakdown->score,
            $breakdown->criteria,
            $breakdown->reasons,
            $profile->getFingerprint(),
            $company->getFingerprint(),
        );

        return $existing;
    }
}
