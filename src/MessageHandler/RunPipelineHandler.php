<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunPipelineMessage;
use App\Service\Pipeline\PipelineRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunPipelineHandler
{
    public function __construct(private PipelineRunner $runner)
    {
    }

    public function __invoke(RunPipelineMessage $message): void
    {
        $this->runner->run($message->trigger);
    }
}
