<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Quest;
use App\Enum\NotificationAudience;
use App\Enum\NotificationType;
use App\Enum\QuestStepType;
use App\Repository\QuestRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The composer on the notifications desk (IP-157). Bound to a plain array; the controller
 * builds the entity, because nothing on a Notification is edited after send and a form bound to
 * the entity would suggest otherwise.
 *
 * The quest picker lists quests whose window is open now. Each option carries the name, the
 * estimated duration and the first room its steps name as data attributes, which
 * admin-notifications.js reads to prefill the two callout templates. The deep link, when given,
 * must be the public site's `/d/<slug>` or `/m/<CODE>` grammar (the same two the app's tap
 * handler resolves); a quest is linked through `quest`, not through this field.
 */
final class NotificationComposeType extends AbstractType
{
    public function __construct(
        #[Autowire(env: 'MONAD_PUBLIC_SITE_URL')]
        private readonly string $publicSiteUrl,
        #[Autowire(env: 'MONAD_ADMIN_TIMEZONE')]
        private readonly string $timezone,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $now = new \DateTimeImmutable();
        $site = rtrim($this->publicSiteUrl, '/');
        $tz = $this->timezone !== '' ? $this->timezone : 'UTC';

        $builder
            ->add('type', ChoiceType::class, [
                'choices' => ['General message' => NotificationType::GENERAL->value, 'Quest callout' => NotificationType::QUEST_CALLOUT->value],
                'expanded' => true,
                'multiple' => false,
                'data' => NotificationType::GENERAL->value,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('title', TextType::class, [
                'attr' => ['maxlength' => 120, 'autocomplete' => 'off'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)],
            ])
            ->add('body', TextareaType::class, [
                'attr' => ['rows' => 4],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 4000)],
            ])
            ->add('quest', EntityType::class, [
                'class' => Quest::class,
                'required' => false,
                'placeholder' => '— none —',
                'choice_label' => static fn (Quest $q): string => (string) $q->getName(),
                'query_builder' => static fn (QuestRepository $r) => $r->createQueryBuilder('q')
                    ->andWhere('q.availableFrom <= :now')
                    ->andWhere('q.availableTo IS NULL OR q.availableTo >= :now')
                    ->setParameter('now', $now)
                    ->orderBy('q.name', 'ASC'),
                'choice_attr' => static fn (Quest $q): array => [
                    'data-name' => (string) $q->getName(),
                    'data-duration' => $q->getEstimatedDuration() === null ? '' : (string) $q->getEstimatedDuration(),
                    'data-room' => self::firstRoom($q) ?? '',
                ],
            ])
            ->add('audience', ChoiceType::class, [
                'choices' => [
                    'Everyone (every active account)' => NotificationAudience::ALL->value,
                    'Beta cohort' => NotificationAudience::BETA->value,
                    'Operators (ROLE_SUPERADMIN)' => NotificationAudience::OPERATORS->value,
                ],
                'expanded' => true,
                'multiple' => false,
                'data' => NotificationAudience::ALL->value,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('deep_link', TextType::class, [
                'required' => false,
                'attr' => ['placeholder' => $site . '/d/<slug> or ' . $site . '/m/<CODE>', 'autocomplete' => 'off'],
                'constraints' => [
                    new Assert\Length(max: 512),
                    new Assert\Regex(
                        pattern: '#^' . preg_quote($site, '#') . '/(d|m)/[A-Za-z0-9._~-]+$#',
                        message: sprintf('A deep link is %s/d/<slug> or %s/m/<CODE>. To open a quest, pick it above instead.', $site, $site),
                    ),
                ],
            ])
            ->add('push', CheckboxType::class, [
                'required' => false,
                'data' => true,
                'label' => 'Also push (to opted-in recipients with a registered token)',
            ])
            ->add('send_mode', ChoiceType::class, [
                'choices' => ['Send now' => 'now', 'Schedule' => 'schedule'],
                'expanded' => true,
                'multiple' => false,
                'data' => 'now',
            ])
            ->add('scheduled_for', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'UTC',
                'view_timezone' => $tz,
                'label' => sprintf('Scheduled for (%s)', $tz),
            ])
            ->add('expires_at', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'UTC',
                'view_timezone' => $tz,
                'label' => sprintf('Expires at (%s), optional', $tz),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'constraints' => [new Assert\Callback([self::class, 'validateSchedule'])],
        ]);
    }

    /**
     * Cross-field rules: a scheduled send needs a future instant; an expiry must be after the
     * send instant, or the inbox would never list the row.
     *
     * @param array<string, mixed> $data
     */
    public static function validateSchedule(mixed $data, ExecutionContextInterface $context): void
    {
        if (!is_array($data)) {
            return;
        }
        $now = new \DateTimeImmutable();
        $scheduled = $data['scheduled_for'] ?? null;
        $expires = $data['expires_at'] ?? null;

        if (($data['send_mode'] ?? 'now') === 'schedule') {
            if (!$scheduled instanceof \DateTimeImmutable) {
                $context->buildViolation('Pick the instant to send at, or choose "Send now".')->atPath('scheduled_for')->addViolation();
            } elseif ($scheduled <= $now) {
                $context->buildViolation('The scheduled instant is in the past. Choose "Send now" or a later time.')->atPath('scheduled_for')->addViolation();
            }
        }

        if ($expires instanceof \DateTimeImmutable) {
            $sendAt = $scheduled instanceof \DateTimeImmutable && ($data['send_mode'] ?? 'now') === 'schedule' ? $scheduled : $now;
            if ($expires <= $sendAt) {
                $context->buildViolation('The expiry must be after the send instant, or nobody would ever see it.')->atPath('expires_at')->addViolation();
            }
        }
    }

    /**
     * The first room a quest's steps name: a probe target's `room`, or a walk_to `location`.
     * Null when the quest names none; the JS then falls back to the building.
     */
    public static function firstRoom(Quest $quest): ?string
    {
        foreach ($quest->getSteps() as $step) {
            $config = $step->getConfig();
            if ($step->getType() === QuestStepType::PROBE) {
                foreach ((array) ($config['targets'] ?? []) as $target) {
                    $room = is_array($target) ? trim((string) ($target['room'] ?? '')) : '';
                    if ($room !== '') {
                        return $room;
                    }
                }
            } elseif ($step->getType() === QuestStepType::WALK_TO) {
                $location = $config['location'] ?? null;
                if (is_string($location) && trim($location) !== '') {
                    return trim($location);
                }
            }
        }

        return null;
    }
}
