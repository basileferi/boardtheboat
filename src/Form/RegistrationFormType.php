<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\RegistrationInput;
use App\Enum\AppLocale;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Bound to a DTO rather than to User: the entity never holds a plain-text password, not even
 * transiently, and the form cannot reach fields such as roles or isVerified.
 *
 * @extends AbstractType<RegistrationInput>
 */
final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'security.register.email',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('locale', EnumType::class, [
                'class' => AppLocale::class,
                'label' => 'security.register.locale',
                'choice_label' => static fn (AppLocale $locale): string => 'locale.'.$locale->value,
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => true,
                'first_options' => [
                    'label' => 'security.register.password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'security.register.password_repeat',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'security.register.password_mismatch',
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'security.register.agree_terms',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationInput::class,
            'csrf_token_id' => 'submit',
        ]);
    }
}
