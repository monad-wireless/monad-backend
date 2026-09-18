<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * The push transport port (IP-157). Two adapters: FCM through kreait/firebase-php when
 * MONAD_FCM_CREDENTIALS names a readable service-account file, and a null adapter otherwise.
 * `PushSenderFactory` picks one; nothing else decides.
 */
interface PushSender
{
    /**
     * One result per token in the message, in the message's order. Never throws for a
     * per-token failure: the handler writes each outcome on the delivery row.
     *
     * @return list<PushResult>
     */
    public function send(PushMessage $message): array;

    /** False for the null adapter: the dispatcher then skips every push instead of queueing it. */
    public function isConfigured(): bool;

    /** One sentence for the desk: what will happen to a push, and why. */
    public function describe(): string;
}
