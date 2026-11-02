<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * le "worker" des hébergements sans crontab : le planificateur web d'infomaniak appelle cette
 * url toutes les 15 minutes, et on traite la file messenger pendant ~40 s (mails en attente,
 * pipeline de nuit, fiches entreprises), puis on répond 204.
 *
 * - désactivée tant que CRON_TOKEN est vide (404, comme si elle n'existait pas) ;
 * - le jeton est comparé en temps constant (hash_equals) ;
 * - une exécution par minute au plus, pour qu'un petit malin ne fasse pas tourner le serveur.
 */
final class CronController
{
    public function __construct(
        private readonly KernelInterface $kernel,
        #[Autowire(env: 'CRON_TOKEN')]
        private readonly string $cronToken,
        #[Autowire(param: 'app.cron_time_limit')]
        private readonly int $timeLimit,
    ) {
    }

    #[Route('/_cron/tick', name: 'app_cron_tick', methods: ['GET', 'POST'])]
    public function tick(
        Request $request,
        #[Autowire(service: 'limiter.cron')]
        RateLimiterFactoryInterface $limiter,
    ): Response {
        $given = $request->query->getString('token', $request->headers->get('X-Cron-Token', ''));

        if ('' === $this->cronToken || !hash_equals($this->cronToken, $given)) {
            throw new NotFoundHttpException();
        }

        if (!$limiter->create('tick')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(60);
        }

        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $application->run(new ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => ['scheduler_default', 'async'],
            '--time-limit' => $this->timeLimit,
            '--memory-limit' => '128M',
            '--no-interaction' => true,
        ]), new NullOutput());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
