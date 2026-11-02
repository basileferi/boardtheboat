<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\IngestionQuery;
use App\Service\Ingestion\SourceRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:jobs:refresh',
    description: 'Fetch real tech internships and companies from the free sources (The Muse, Himalayas, Arbeitnow, France Travail, Recherche d\'entreprises).',
)]
final class RefreshSourcesCommand extends Command
{
    public function __construct(private readonly SourceRefresher $refresher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only run these sources (repeatable)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum offers kept per source', '150')
            ->addOption('departement', 'd', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Départements searched for companies (repeatable)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $only */
        $only = $input->getOption('source');
        /** @var list<string> $departements */
        $departements = $input->getOption('departement');

        $query = new IngestionQuery(
            departements: [] === $departements ? IngestionQuery::DEFAULT_DEPARTEMENTS : $departements,
            limitPerSource: max(1, (int) $input->getOption('limit')),
        );

        $io->text(\sprintf('Sources: %s', implode(', ', [] === $only ? $this->refresher->sourceNames() : $only)));

        $run = $this->refresher->refresh(
            $query,
            'console',
            [] === $only ? null : $only,
            static fn (string $source, int $count) => $io->text(\sprintf(' <info>✔</info> %-24s %d', $source, $count)),
        );

        $io->table(['Step', 'Count'], array_map(null, array_keys($run->getCounts()), array_values($run->getCounts())));

        foreach ($run->getErrors() as $error) {
            $io->warning($error);
        }

        $io->success(\sprintf('Run finished in %ds with status "%s".', $run->getDurationSeconds() ?? 0, $run->getStatus()->value));

        return Command::SUCCESS;
    }
}
