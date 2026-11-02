<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\Http\SafeHttpFetcher;
use App\Service\Http\UnsafeUrlException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * applique exactement les mêmes règles que SafeHttpFetcher, mais au moment de la saisie :
 * l'utilisateur voit tout de suite pourquoi son lien est refusé, au lieu d'un échec silencieux
 * plus tard dans le worker. aucune requête réseau n'est faite ici.
 */
final class SafeUrlValidator extends ConstraintValidator
{
    public function __construct(private readonly SafeHttpFetcher $fetcher)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SafeUrl) {
            throw new UnexpectedTypeException($constraint, SafeUrl::class);
        }

        // champ vide : c'est à NotBlank de décider, pas à nous
        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        try {
            $this->fetcher->assertSafe($value);
        } catch (UnsafeUrlException) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ url }}', $value)
                ->addViolation();
        }
    }
}
