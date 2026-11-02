<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "I forgot my password": only an address, never bound to an entity.
 *
 * @extends AbstractType<array{email: string|null}>
 */
final class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'security.reset.email',
            'attr' => ['autocomplete' => 'email'],
            'constraints' => [
                new Assert\NotBlank(message: 'security.reset.email_required'),
                new Assert\Email(message: 'security.register.email_invalid'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'submit']);
    }
}
