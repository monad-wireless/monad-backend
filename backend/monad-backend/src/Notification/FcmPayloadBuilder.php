<?php

declare(strict_types=1);

namespace App\Notification;

use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

/**
 * The FCM HTTP v1 message for one token (IP-157). Pure: no client, no network, so the payload
 * contract with the app is pinned by a unit test rather than by a phone.
 *
 * Shape: a `notification` block (title, body) so the OS shows it when the app is not running,
 * plus the `data` block the app reads on tap. Android gets the channel id per type so the user
 * can silence callouts in system settings. APNs is left at kreait's defaults on purpose: the
 * notification block is enough for an alert, and a custom `aps` here would be a second place
 * the iOS presentation is decided.
 */
final class FcmPayloadBuilder
{
    public function build(PushMessage $message, string $token): CloudMessage
    {
        return CloudMessage::new()
            ->withToken($token)
            ->withNotification(FcmNotification::create($message->title, $message->body))
            ->withData($message->data())
            ->withAndroidConfig([
                'notification' => ['channel_id' => $message->androidChannelId()],
            ]);
    }
}
