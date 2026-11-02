<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use App\Entity\Company;
use App\Enum\CareerPageStatus;
use App\Service\Http\SafeHttpFetcher;
use App\Service\Http\UnsafeUrlException;

/**
 * vérifie qu'une page carrières existe vraiment et parle de recrutement.
 *
 * "valid" = la page répond, c'est du html et on y trouve un mot qui sent l'embauche.
 * "blocked" = robots.txt nous l'interdit, on n'insiste pas.
 * "invalid" = tout le reste (404, pas de html, adresse refusée...).
 */
final readonly class CareerPageChecker
{
    private const string HIRING_WORDS = '/carri[eè]re|recrut|emploi|offres?|stage|altern|job|career|hiring|join us|rejoindre/iu';

    public function __construct(private SafeHttpFetcher $fetcher)
    {
    }

    public function check(Company $company): CareerPageStatus
    {
        $url = $company->getCareerUrl() ?? $company->getWebsite();
        if (null === $url) {
            return CareerPageStatus::Invalid;
        }

        try {
            $result = $this->fetcher->fetch($url);
        } catch (UnsafeUrlException $exception) {
            return str_contains($exception->getMessage(), 'robots')
                ? CareerPageStatus::Blocked
                : CareerPageStatus::Invalid;
        }

        if (!$result->isOk() || !$result->isHtml()) {
            return CareerPageStatus::Invalid;
        }

        return 1 === preg_match(self::HIRING_WORDS, $result->body)
            ? CareerPageStatus::Valid
            : CareerPageStatus::Invalid;
    }
}
