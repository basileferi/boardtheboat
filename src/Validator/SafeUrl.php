<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * notre propre contrainte (cours 7, "construire ses propres règles de validation") :
 * une url ne passe que si on pourrait un jour aller la lire sans danger
 * (http/https, pas d'identifiants, pas d'adresse privée ou locale).
 *
 * utilisation : #[SafeUrl] au-dessus d'une propriété.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class SafeUrl extends Constraint
{
    public string $message = 'company.url.unsafe';

    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}
