<?php

declare(strict_types=1);

namespace App\Message;

/**
 * "prépare la fiche de cette entreprise".
 *
 * envoyé par PipelineRunner pour les entreprises du top-n de chaque utilisateur qui n'ont pas
 * encore de fiche fraîche. traité en async. le handler est idempotent : relancé deux fois, il
 * retrouve la même fiche et revérifie juste la page carrières.
 */
final readonly class EnrichCompanyMessage
{
    public function __construct(public string $companyId)
    {
    }
}
