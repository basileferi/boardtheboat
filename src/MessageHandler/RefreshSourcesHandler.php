<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Dto\IngestionQuery;
use App\Message\RefreshSourcesMessage;
use App\Service\Ingestion\SourceRefresher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshSourcesHandler
{
    public function __construct(private SourceRefresher $refresher)
    {
    }

    public function __invoke(RefreshSourcesMessage $message): void
    {
        $this->refresher->refresh(new IngestionQuery(), $message->trigger);
    }
}
