<?php

declare(strict_types=1);

namespace App;

use App\Message\RunPipelineMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // a night missed is not worth two refreshes
            // Nightly, at an hour the free APIs are quiet; the cron-driven consumer picks it up.
            ->add(RecurringMessage::every('1 day', new RunPipelineMessage('scheduler'), from: new \DateTimeImmutable('03:00', new \DateTimeZone('Europe/Paris'))))
        ;
    }
}
