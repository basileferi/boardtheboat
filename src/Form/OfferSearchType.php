<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\OfferSearchCriteria;
use App\Enum\ContractType;
use App\Enum\OfferStatus;
use App\Enum\Region;
use App\Enum\WorkMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The offer filters, submitted over GET so every search is a shareable, bookmarkable URL.
 *
 * CSRF protection is off here on purpose: this form performs no state change, and a token in
 * the query string would only make the shared URL expire.
 *
 * @extends AbstractType<OfferSearchCriteria>
 */
final class OfferSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('keyword', SearchType::class, [
                'required' => false,
                'label' => 'search.keyword',
                'attr' => ['placeholder' => 'search.keyword_placeholder'],
            ])
            ->add('company', TextType::class, [
                'required' => false,
                'label' => 'search.company',
            ])
            ->add('location', TextType::class, [
                'required' => false,
                'label' => 'search.location',
            ])
            ->add('contractTypes', EnumType::class, [
                'class' => ContractType::class,
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'label' => 'search.contract_type',
                'choice_label' => static fn (ContractType $type): string => 'contract_type.'.$type->value,
            ])
            ->add('workModes', EnumType::class, [
                'class' => WorkMode::class,
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'label' => 'search.work_mode',
                'choice_label' => static fn (WorkMode $mode): string => 'work_mode.'.$mode->value,
            ])
            // The three below always hold a value. They declare empty_data because a shared
            // URL may well carry only ?keyword=..., and a missing parameter must fall back to
            // the default rather than write null into a non-nullable property.
            ->add('status', EnumType::class, [
                'class' => OfferStatus::class,
                'label' => 'search.status',
                'placeholder' => false,
                'empty_data' => OfferStatus::Open->value,
                'choice_label' => static fn (OfferStatus $status): string => 'offer_status.'.$status->value,
            ])
            ->add('region', EnumType::class, [
                'class' => Region::class,
                'label' => 'search.region',
                'placeholder' => false,
                'empty_data' => Region::France->value,
                'choice_label' => static fn (Region $region): string => 'region.'.$region->value,
            ])
            ->add('sort', ChoiceType::class, [
                'label' => 'search.sort',
                'placeholder' => false,
                'empty_data' => OfferSearchCriteria::SORT_NEWEST,
                'choices' => [
                    'search.sort_newest' => OfferSearchCriteria::SORT_NEWEST,
                    'search.sort_relevance' => OfferSearchCriteria::SORT_RELEVANCE,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OfferSearchCriteria::class,
            'method' => 'GET',
            'csrf_protection' => false,
            // A shared search URL rarely carries every parameter, so unknown extras are
            // tolerated instead of invalidating the whole form.
            'allow_extra_fields' => true,
        ]);
    }
}
