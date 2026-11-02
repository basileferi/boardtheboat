<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Pipeline\PipelineRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:pipeline:run',
    description: 'Run the automatic mode end to end: discover, dedupe, rank every profile, queue the top-n briefs, report.',
)]
final class RunPipelineCommand extends Command
{
    public function __construct(private readonly PipelineRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-discover', null, InputOption::VALUE_NONE, 'Rank and queue briefs from what is already in the database, without calling the sources');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $run = $this->runner->run('console', !$input->getOption('skip-discover'));

        $io->table(['étape', 'nombre'], array_map(null, array_keys($run->getCounts()), array_values($run->getCounts())));

        foreach ($run->getErrors() as $error) {
            $io->warning($error);
        }

        $io->success(\sprintf('pipeline terminé en %ds (%s). les fiches en attente partent au worker : messenger:consume async', $run->getDurationSeconds() ?? 0, $run->getStatus()->value));

        return Command::SUCCESS;
    }
}
