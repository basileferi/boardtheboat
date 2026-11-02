<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\CompanyInput;
use App\Enum\CompanySize;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * formulaire d'ajout / modification d'une entreprise (au départ généré avec make:crud, puis
 * branché sur un dto et nettoyé).
 *
 * @extends AbstractType<CompanyInput>
 */
final class CompanyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // empty_data '' : un champ vidé envoie null, et name n'accepte pas null (cours 7)
            ->add('name', TextType::class, ['label' => 'company.field.name', 'empty_data' => ''])
            ->add('website', UrlType::class, [
                'label' => 'company.field.website',
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('careerUrl', UrlType::class, [
                'label' => 'company.field.career_url',
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('city', TextType::class, ['label' => 'company.field.city', 'required' => false])
            ->add('country', CountryType::class, ['label' => 'company.field.country', 'required' => false, 'preferred_choices' => ['FR']])
            ->add('sector', TextType::class, ['label' => 'company.field.sector', 'required' => false])
            ->add('size', EnumType::class, [
                'class' => CompanySize::class,
                'label' => 'company.field.size',
                'choice_label' => static fn (CompanySize $size): string => 'company_size.'.$size->value,
            ])
            ->add('siren', TextType::class, ['label' => 'company.field.siren', 'required' => false])
            ->add('whyInteresting', TextareaType::class, ['label' => 'company.field.why', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('notes', TextareaType::class, ['label' => 'company.field.notes', 'required' => false, 'attr' => ['rows' => 4]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompanyInput::class,
            'csrf_token_id' => 'company',
        ]);
    }
}
