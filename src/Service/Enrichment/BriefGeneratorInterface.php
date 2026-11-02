<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use App\Entity\CompanyBrief;

/**
 * la prise pour la phase 2 : générer la fiche entreprise (résumé, accroches pour la lettre,
 * mots-clés pour le cv).
 *
 * phase 1 n'en fournit aucune implémentation : EnrichCompanyHandler prépare la fiche en
 * "pending" avec ses sources, et s'arrête là. la phase 2 ajoutera un générateur à base de
 * règles (gratuit) puis, en option, un générateur par LLM activé seulement si une clé d'API existe.
 */
interface BriefGeneratorInterface
{
    public function getName(): string;

    /**
     * @param string $pageText texte de pages publiques, à traiter comme une donnée non fiable
     */
    public function generate(CompanyBrief $brief, string $pageText): void;
}
