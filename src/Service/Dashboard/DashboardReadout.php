<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\User;
use App\Enum\PipelineStatus;
use App\Repository\ApplicationRepository;
use App\Repository\CompanyRepository;
use App\Repository\JobOfferRepository;
use App\Repository\PipelineRunRepository;
use App\Repository\RelevanceScoreRepository;
use App\Service\Ingestion\Source\RechercheEntreprisesSource;
use Symfony\Component\Clock\ClockInterface;

/**
 * Everything the dashboard shows, computed in one place so the controller stays a pass-through
 * and the figures can be tested without rendering a page.
 *
 * Bar heights and widths are returned already rounded to steps of five, because the templates
 * turn them into CSS classes: the strict Content-Security-Policy forbids inline style attributes.
 */
final readonly class DashboardReadout
{
    public const int ACTIVITY_DAYS = 14;

    public function __construct(
        private JobOfferRepository $offers,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private RelevanceScoreRepository $scores,
        private PipelineRunRepository $runs,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $now = $this->clock->now();
        $weekAgo = $now->modify('-7 days');
        $counts = $this->applications->countsByStatus($user);
        $due = $this->applications->dueFollowUps($user, $now);
        $bySource = $this->companies->countCatalogueBySource();
        $activity = $this->activity($now);

        $sent = ($counts[PipelineStatus::Applied->value] ?? 0) + ($counts[PipelineStatus::Interview->value] ?? 0)
            + ($counts[PipelineStatus::Rejected->value] ?? 0) + ($counts[PipelineStatus::Offer->value] ?? 0);
        $answered = ($counts[PipelineStatus::Interview->value] ?? 0) + ($counts[PipelineStatus::Offer->value] ?? 0);

        return [
            'kpis' => [
                ['key' => 'open_offers', 'value' => $this->offers->countOpen(), 'delta' => $this->offers->countOpenSince($weekAgo), 'tone' => 'purple', 'spark' => $activity],
                ['key' => 'france_offers', 'value' => $this->offers->countOpenInCountry('FR'), 'delta' => $this->offers->countOpenSince($weekAgo, 'FR'), 'tone' => 'pink'],
                ['key' => 'companies', 'value' => $this->companies->countCatalogue(), 'delta' => $bySource[RechercheEntreprisesSource::NAME] ?? 0, 'tone' => 'orange'],
                ['key' => 'tracked', 'value' => array_sum($counts), 'delta' => $sent, 'tone' => 'purple'],
                ['key' => 'response_rate', 'value' => 0 === $sent ? 0 : (int) round(100 * $answered / $sent), 'delta' => $answered, 'tone' => 'pink', 'suffix' => '%'],
                ['key' => 'due', 'value' => \count($due), 'delta' => null, 'tone' => [] === $due ? 'orange' : 'signal'],
            ],
            'activity' => $activity,
            'sources' => $this->shares($this->offers->countOpenBySource()),
            'funnel' => $this->funnel($counts),
            'latestOffers' => $this->offers->latestOpen(6),
            'due' => $due,
            'topScores' => $this->scores->topFor($user, 5),
            'lastRun' => $this->runs->latest(),
        ];
    }

    /**
     * Offers published per day over the last two weeks, oldest first.
     *
     * @return list<array{date: \DateTimeImmutable, count: int, height: int}>
     */
    private function activity(\DateTimeImmutable $now): array
    {
        $start = $now->setTime(0, 0)->modify(\sprintf('-%d days', self::ACTIVITY_DAYS - 1));

        $days = [];
        for ($i = 0; $i < self::ACTIVITY_DAYS; ++$i) {
            $days[$start->modify(\sprintf('+%d days', $i))->format('Y-m-d')] = 0;
        }

        foreach ($this->offers->publicationDatesSince($start) as $published) {
            $key = $published->setTimezone($now->getTimezone())->format('Y-m-d');
            if (isset($days[$key])) {
                ++$days[$key];
            }
        }

        $max = max(1, ...array_values($days));
        $bars = [];
        foreach ($days as $day => $count) {
            $bars[] = [
                'date' => new \DateTimeImmutable($day, $now->getTimezone()),
                'count' => $count,
                'height' => 0 === $count ? 0 : max(5, self::step(100 * $count / $max)),
            ];
        }

        return $bars;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{name: string, count: int, width: int}>
     */
    private function shares(array $counts): array
    {
        $max = max(1, ...array_values($counts ?: [0]));
        $rows = [];
        foreach ($counts as $name => $count) {
            $rows[] = ['name' => $name, 'count' => $count, 'width' => max(5, self::step(100 * $count / $max))];
        }

        return $rows;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{status: string, count: int, width: int}>
     */
    private function funnel(array $counts): array
    {
        $max = max(1, ...array_values($counts ?: [0]));
        $rows = [];
        foreach (PipelineStatus::cases() as $status) {
            $count = $counts[$status->value] ?? 0;
            $rows[] = ['status' => $status->value, 'count' => $count, 'width' => 0 === $count ? 0 : max(5, self::step(100 * $count / $max))];
        }

        return $rows;
    }

    /**
     * Rounds a percentage to the nearest step of five, the resolution of the CSS utility classes.
     */
    public static function step(float $percent): int
    {
        return (int) (5 * round(max(0, min(100, $percent)) / 5));
    }
}
