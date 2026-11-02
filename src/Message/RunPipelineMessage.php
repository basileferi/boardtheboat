<?php

declare(strict_types=1);

namespace App\Message;

/**
 * "lance le pipeline complet" (sonder, fusionner, classer, préparer, rapport).
 *
 * envoyé chaque nuit par App\Schedule. traité en async par le worker. idempotent : les sources
 * font des upserts et le classement ne recalcule que ce qui a changé, donc deux runs d'affilée
 * ne dupliquent rien.
 */
final readonly class RunPipelineMessage
{
    public function __construct(public string $trigger = 'scheduler')
    {
    }
}
