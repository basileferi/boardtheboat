<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Dto\IngestionQuery;
use App\Entity\PipelineRun;
use App\Message\EnrichCompanyMessage;
use App\Repository\CompanyBriefRepository;
use App\Repository\RelevanceScoreRepository;
use App\Repository\UserRepository;
use App\Service\Ingestion\SourceRefresher;
use App\Service\Matching\CompanyRanker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

/**
 * le mode automatique, de bout en bout :
 *
 *  1. sonder   — chaque source activée (offres + registre d'entreprises) ;
 *  2. fusionner — fait pendant l'ingestion : siren, puis domaine, puis nom normalisé ;
 *  3. classer  — RelevanceScorer pour chaque utilisateur qui a un profil (seuls les couples
 *                qui ont changé sont recalculés) ;
 *  4. préparer — une EnrichCompanyMessage pour les entreprises du top-n sans fiche fraîche ;
 *  5. rapport  — tout est compté dans un seul PipelineRun, et l'admin reçoit un mail si ça casse.
 *
 * une étape qui plante n'arrête pas les suivantes : le run finit "partial", pas "failed".
 */
final readonly class PipelineRunner
{
    public function __construct(
        private SourceRefresher $refresher,
        private UserRepository $users,
        private CompanyRanker $ranker,
        private RelevanceScoreRepository $scores,
        private CompanyBriefRepository $briefs,
        private MessageBusInterface $bus,
        private NotifierInterface $notifier,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.admin_alert_address')]
        private string $adminAlertAddress,
    ) {
    }

    public function run(string $trigger, bool $discover = true, ?IngestionQuery $query = null): PipelineRun
    {
        $run = $discover
            ? $this->refresher->refresh($query ?? new IngestionQuery(), $trigger)
            : $this->startRun($trigger);

        $this->rank($run);
        $this->queueBriefs($run);

        $run->finish();
        $this->entityManager->flush();

        if ([] !== $run->getErrors()) {
            $this->alertAdmin($run);
        }

        return $run;
    }

    private function rank(PipelineRun $run): void
    {
        foreach ($this->users->findWithProfile() as $user) {
            $profile = $user->getProfile();
            if (null === $profile) {
                continue;
            }

            try {
                $run->addCount('scored', $this->ranker->rankAllFor($profile));
                $run->addCount('profiles', 1);
            } catch (\Throwable $exception) {
                $run->addError(\sprintf('classement de %s : %s', $user->getUserIdentifier(), $exception->getMessage()));
            }
        }
    }

    private function queueBriefs(PipelineRun $run): void
    {
        $queued = [];

        foreach ($this->users->findWithProfile() as $user) {
            $topN = $user->getProfile()?->getTopN() ?? 20;

            foreach ($this->scores->topFor($user, $topN) as $score) {
                $company = $score->getCompany();
                $id = (string) $company->getId();

                if (isset($queued[$id]) || $this->briefs->hasFreshBrief($company)) {
                    continue;
                }

                $this->bus->dispatch(new EnrichCompanyMessage($id));
                $queued[$id] = true;
            }
        }

        $run->addCount('briefs_queued', \count($queued));
    }

    private function startRun(string $trigger): PipelineRun
    {
        $run = new PipelineRun($trigger);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function alertAdmin(PipelineRun $run): void
    {
        $this->logger->warning('pipeline run finished with errors', ['errors' => $run->getErrors()]);

        $notification = (new Notification('boardtheboat : le pipeline a rencontré des erreurs', ['email']))
            ->content(implode("\n", $run->getErrors()))
            ->importance(Notification::IMPORTANCE_HIGH);

        $this->notifier->send($notification, new Recipient($this->adminAlertAddress));
    }
}
