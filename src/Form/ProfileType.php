<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Profile;
use App\Enum\Region;
use App\Enum\RemotePreference;
use App\Form\Transformer\CommaSeparatedListTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The one form that drives the automatic mode: everything the scorer reads lives here.
 *
 * @extends AbstractType<Profile>
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $list = new CommaSeparatedListTransformer();

        $builder
            ->add('targetRoles', TextType::class, [
                'required' => false,
                'label' => 'profile.target_roles',
                'help' => 'profile.comma_separated',
            ])
            ->add('keywords', TextType::class, [
                'required' => false,
                'label' => 'profile.keywords',
                'help' => 'profile.comma_separated',
            ])
            ->add('sectors', TextType::class, [
                'required' => false,
                'label' => 'profile.sectors',
                'help' => 'profile.comma_separated',
            ])
            ->add('skills', TextType::class, [
                'required' => false,
                'label' => 'profile.skills',
                'help' => 'profile.comma_separated',
            ])
            ->add('preferredLocations', TextType::class, [
                'required' => false,
                'label' => 'profile.locations',
                'help' => 'profile.comma_separated',
            ])
            ->add('languages', TextType::class, [
                'required' => false,
                'label' => 'profile.languages',
                'help' => 'profile.comma_separated',
            ])
            ->add('remotePreference', EnumType::class, [
                'class' => RemotePreference::class,
                'label' => 'profile.remote_preference',
                'choice_label' => static fn (RemotePreference $case): string => 'remote_preference.'.$case->value,
            ])
            ->add('defaultRegion', EnumType::class, [
                'class' => Region::class,
                'label' => 'profile.default_region',
                'choice_label' => static fn (Region $case): string => 'region.'.$case->value,
            ])
            ->add('internshipStartDate', DateType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'profile.start_date',
            ])
            ->add('internshipDurationWeeks', IntegerType::class, [
                'required' => false,
                'label' => 'profile.duration_weeks',
            ])
            ->add('relanceDelayDays', IntegerType::class, [
                'label' => 'profile.relance_delay',
                'help' => 'profile.relance_delay_help',
            ])
            ->add('maxRelances', IntegerType::class, [
                'label' => 'profile.max_relances',
            ])
            ->add('topN', IntegerType::class, [
                'label' => 'profile.top_n',
                'help' => 'profile.top_n_help',
            ])
            ->add('cvText', TextareaType::class, [
                'required' => false,
                'label' => 'profile.cv_text',
                'help' => 'profile.cv_text_help',
                'attr' => ['rows' => 10],
            ]);

        foreach (['targetRoles', 'keywords', 'sectors', 'skills', 'preferredLocations', 'languages'] as $field) {
            $builder->get($field)->addModelTransformer($list);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Profile::class,
            'csrf_token_id' => 'submit',
        ]);
    }
}
