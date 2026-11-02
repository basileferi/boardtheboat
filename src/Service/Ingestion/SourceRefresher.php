<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

use App\Dto\IngestionQuery;
use App\Entity\PipelineRun;
use App\Repository\JobOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

/**
 * Runs the enabled sources and records the run as a PipelineRun.
 *
 * One failing provider never stops the others: its error is written on the run, and the run
 * ends "partial" instead of "failed". Offers a source stopped listing are expired once they
 * are older than the window that source returns.
 */
final readonly class SourceRefresher
{
    private const int FLUSH_EVERY = 50;

    private const string EXPIRE_AFTER = '-45 days';

    /**
     * @param iterable<JobSourceInterface>     $jobSources
     * @param iterable<CompanySourceInterface> $companySources
     */
    public function __construct(
        #[AutowireIterator('app.job_source')]
        private iterable $jobSources,
        #[AutowireIterator('app.company_source')]
        private iterable $companySources,
        private Ingestor $ingestor,
        private JobOfferRepository $offers,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string>|null                  $only     source names to run; null runs all enabled
     * @param (callable(string, int): void)|null $progress called after each source with its count
     */
    public function refresh(IngestionQuery $query, string $trigger, ?array $only = null, ?callable $progress = null): PipelineRun
    {
        $run = new PipelineRun($trigger);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        foreach ($this->jobSources as $source) {
            if ($this->skip($source->getName(), $source->isEnabled(), $only)) {
                continue;
            }

            $count = $this->runJobSource($source, $query, $run);
            if (null !== $progress) {
                $progress($source->getName(), $count);
            }
        }

        foreach ($this->companySources as $source) {
            if ($this->skip($source->getName(), $source->isEnabled(), $only)) {
                continue;
            }

            $count = $this->runCompanySource($source, $query, $run);
            if (null !== $progress) {
                $progress($source->getName(), $count);
            }
        }

        $run->finish();
        $this->entityManager->flush();

        return $run;
    }

    /**
     * @return list<string>
     */
    public function sourceNames(): array
    {
        $names = [];
        foreach ($this->jobSources as $source) {
            $names[] = $source->getName();
        }
        foreach ($this->companySources as $source) {
            $names[] = $source->getName();
        }

        return $names;
    }

    private function runJobSource(JobSourceInterface $source, IngestionQuery $query, PipelineRun $run): int
    {
        /** @var array<string, true> $seen */
        $seen = [];

        try {
            foreach ($source->fetch($query) as $offer) {
                // Paginated feeds shift while we read them: the same offer can come back twice.
                if (isset($seen[$offer->externalId])) {
                    continue;
                }
                $seen[$offer->externalId] = true;
                $run->addCount($this->ingestor->upsertOffer($offer) ? 'offers_created' : 'offers_updated');
                if (0 === \count($seen) % self::FLUSH_EVERY) {
                    $this->ingestor->flush();
                }
            }
            $this->ingestor->finish();

            if ([] !== $seen) {
                $run->addCount('offers_expired', $this->offers->expireUnseen($source->getName(), array_map(strval(...), array_keys($seen)), new \DateTimeImmutable(self::EXPIRE_AFTER)));
            }
        } catch (HttpException|\JsonException|\RuntimeException $exception) {
            $this->fail($source->getName(), $exception, $run);
        }

        $run->addCount('source.'.$source->getName(), \count($seen));

        return \count($seen);
    }

    private function runCompanySource(CompanySourceInterface $source, IngestionQuery $query, PipelineRun $run): int
    {
        $count = 0;

        try {
            foreach ($source->fetch($query) as $company) {
                ++$count;
                $run->addCount($this->ingestor->upsertCompany($company) ? 'companies_created' : 'companies_updated');
                if (0 === $count % self::FLUSH_EVERY) {
                    $this->ingestor->flush();
                }
            }
            $this->ingestor->finish();
        } catch (HttpException|\JsonException|\RuntimeException $exception) {
            $this->fail($source->getName(), $exception, $run);
        }

        $run->addCount('source.'.$source->getName(), $count);

        return $count;
    }

    /**
     * @param list<string>|null $only
     */
    private function skip(string $name, bool $enabled, ?array $only): bool
    {
        return null === $only ? !$enabled : !\in_array($name, $only, true) || !$enabled;
    }

    private function fail(string $source, \Throwable $exception, PipelineRun $run): void
    {
        // Rows written before the failure are kept: they are valid on their own.
        $this->ingestor->finish();
        $run->addError(\sprintf('%s: %s', $source, mb_substr($exception->getMessage(), 0, 300)));
        $this->logger->warning('Source {source} failed during refresh.', ['source' => $source, 'exception' => $exception]);
    }
}
