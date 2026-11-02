<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Choosing a new password at the end of the reset flow. Unmapped on purpose: the plain
 * password is hashed by the controller and never assigned to the entity.
 *
 * @extends AbstractType<array{}>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'first_options' => [
                'label' => 'security.reset.new_password',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'security.register.password_required'),
                    new Assert\Length(min: 12, max: 4096, minMessage: 'security.register.password_too_short'),
                    new Assert\NotCompromisedPassword(message: 'security.register.password_compromised', skipOnError: true),
                ],
            ],
            'second_options' => [
                'label' => 'security.register.password_repeat',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'security.register.password_mismatch',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'submit']);
    }
}
