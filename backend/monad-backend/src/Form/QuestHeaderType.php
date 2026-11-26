<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Device;
use App\Entity\Quest;
use App\Enum\RecurrenceScope;
use App\Repository\DeviceRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The quest builder's header (IP-157). Bound to QuestHeaderData, never to the entity.
 *
 * Every field bound to a NON-NULLABLE property on QuestHeaderData declares `empty_data`.
 * Symfony maps an empty submission to null, and the PropertyAccessor then throws
 * InvalidTypeException rather than adding a form error — a 500 on an empty textarea. The
 * declared defaults are the same ones QuestHeaderData initialises itself with, so an omitted
 * field means what the object already meant.
 */
final class QuestHeaderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'constraints' => [new Assert\NotBlank(message: 'Quest name is required'), new Assert\Length(max: 255)],
            ])
            ->add('description', TextareaType::class, [
                'constraints' => [new Assert\NotBlank(message: 'Quest description is required')],
                'attr' => ['rows' => 4],
            ])
            ->add('audience', ChoiceType::class, [
                'choices' => ['public' => Quest::AUDIENCE_PUBLIC, 'operator' => Quest::AUDIENCE_OPERATOR],
                'expanded' => true,
                'empty_data' => Quest::AUDIENCE_PUBLIC,
            ])
            ->add('availableFrom', DateTimeType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: 'Available from date is required')],
            ])
            ->add('availableTo', DateTimeType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('points', NumberType::class, [
                'scale' => 1,
                'empty_data' => '0',
                'constraints' => [new Assert\PositiveOrZero(message: 'Points must be a positive number or zero')],
            ])
            ->add('estimatedDuration', IntegerType::class, [
                'required' => false,
                'constraints' => [new Assert\Positive(message: 'Estimated duration must be a positive number')],
            ])
            ->add('recurrenceScope', ChoiceType::class, [
                'choices' => [
                    'unlimited' => QuestHeaderData::SCOPE_UNLIMITED,
                    'per device' => RecurrenceScope::PER_DEVICE->value,
                    'per quest' => RecurrenceScope::PER_QUEST->value,
                ],
                'empty_data' => QuestHeaderData::SCOPE_UNLIMITED,
            ])
            ->add('recurrenceCooldownSeconds', IntegerType::class, [
                'required' => false,
                'constraints' => [new Assert\PositiveOrZero()],
            ])
            ->add('requiredCapabilities', ChoiceType::class, [
                'choices' => array_combine(QuestHeaderData::CAPABILITIES, QuestHeaderData::CAPABILITIES),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('armedDevices', EntityType::class, [
                'class' => Device::class,
                'choice_label' => static fn (Device $d): string => $d->getSlug() . ' · ' . $d->getLabel(),
                'query_builder' => static fn (DeviceRepository $r) => $r->createQueryBuilder('d')
                    ->andWhere('d.isActive = true')->orderBy('d.slug', 'ASC'),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('routeMode', ChoiceType::class, [
                'choices' => ['fixed' => QuestHeaderData::ROUTE_FIXED, 'pool' => QuestHeaderData::ROUTE_POOL],
                'expanded' => true,
                'empty_data' => QuestHeaderData::ROUTE_FIXED,
            ])
            ->add('routes', TextareaType::class, [
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 4, 'class' => 'font-monospace', 'placeholder' => "MONAD-FP-15, monad02, MONAD-FP-07\nmonad07, MONAD-FP-18"],
            ])
            ->add('featureBroadcast', CheckboxType::class, ['required' => false])
            ->add('featureTrack', CheckboxType::class, ['required' => false])
            ->add('featureWitness', CheckboxType::class, ['required' => false])
            ->add('featureIlluminator', CheckboxType::class, ['required' => false])
            // The island's one field. `[]` rather than '' when absent: the controller decodes it,
            // and an empty string is not a JSON list.
            ->add('stepsJson', HiddenType::class, ['empty_data' => '[]']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuestHeaderData::class,
            'csrf_token_id' => 'quest_header',
        ]);
    }
}
