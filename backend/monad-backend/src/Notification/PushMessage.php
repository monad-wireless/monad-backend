<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Notification;
use App\Enum\NotificationType;

/**
 * What one push carries, detached from the entity so a sender never touches Doctrine (IP-157).
 *
 * `tokens` is the fan-out for ONE recipient: every unrevoked registration the user holds. The
 * three data keys the app reads on tap are the wire contract fixed by the app lane on
 * 2026-09-16: `notification_id` (marked read), `quest_id` (opens the quest, wins when both are
 * present) and `deep_link` (the site's `/d/<slug>` or `/m/<CODE>` grammar).
 */
final readonly class PushMessage
{
    /**
     * @param list<string> $tokens
     */
    public function __construct(
        public string $notificationId,
        public NotificationType $type,
        public string $title,
        public string $body,
        public ?string $questId,
        public ?string $deepLink,
        public array $tokens,
    ) {
    }

    /** @param list<string> $tokens */
    public static function fromNotification(Notification $notification, array $tokens): self
    {
        return new self(
            $notification->getId()->toRfc4122(),
            $notification->getType(),
            $notification->getTitle(),
            $notification->getBody(),
            $notification->getQuest()?->getId()?->toRfc4122(),
            $notification->getDeepLink(),
            array_values($tokens),
        );
    }

    /**
     * The FCM `data` block. Strings only: FCM rejects any other scalar, and the app parses them
     * as strings.
     *
     * @return array<non-empty-string, string>
     */
    public function data(): array
    {
        $data = ['notification_id' => $this->notificationId];
        if ($this->questId !== null && $this->questId !== '') {
            $data['quest_id'] = $this->questId;
        }
        if ($this->deepLink !== null && $this->deepLink !== '') {
            $data['deep_link'] = $this->deepLink;
        }

        return $data;
    }

    /** The Android channel the app registers for this type (IP-157 Phase 6). */
    public function androidChannelId(): string
    {
        return $this->type === NotificationType::QUEST_CALLOUT ? 'quest_callouts' : 'messages';
    }
}
