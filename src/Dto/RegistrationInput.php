<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\User;
use App\Enum\AppLocale;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the sign-up form is allowed to carry. Not readonly: the Form component writes into it.
 *
 * the uniqueness check lives here too, otherwise an already-used address only blew up at
 * flush time as a database error instead of a readable message under the field.
 */
#[UniqueEntity(fields: ['email'], message: 'user.email.already_used', entityClass: User::class, errorPath: 'email')]
final class RegistrationInput
{
    #[Assert\NotBlank(message: 'security.register.email_required')]
    #[Assert\Email(message: 'security.register.email_invalid')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    public AppLocale $locale = AppLocale::French;

    /**
     * Length is the only rule that measurably helps; composition rules push users towards
     * predictable substitutions. The value never touches the database.
     */
    #[Assert\NotBlank(message: 'security.register.password_required')]
    #[Assert\Length(min: 12, max: 4096, minMessage: 'security.register.password_too_short')]
    #[Assert\NotCompromisedPassword(message: 'security.register.password_compromised', skipOnError: true)]
    public ?string $plainPassword = null;

    #[Assert\IsTrue(message: 'security.register.agree_terms_required')]
    public bool $agreeTerms = false;
}
