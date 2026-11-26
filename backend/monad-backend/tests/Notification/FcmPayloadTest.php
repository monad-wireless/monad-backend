<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Enum\NotificationType;
use App\Notification\FcmPayloadBuilder;
use App\Notification\PushMessage;
use PHPUnit\Framework\TestCase;

/**
 * The FCM HTTP v1 payload the app lane built against (IP-157 wire contract, 2026-09-16).
 * No client, no network: the CloudMessage is serialised and read back as the JSON FCM would get.
 */
final class FcmPayloadTest extends TestCase
{
    private const NOTIFICATION_ID = '0c2c3a4e-9b1a-4b6f-8f2e-1d7f3a5b9c01';
    private const QUEST_ID = '7d1e2f3a-4b5c-4d6e-8f70-8192a3b4c5d6';

    public function testCalloutCarriesQuestIdDeepLinkAndTheCalloutChannel(): void
    {
        $message = new PushMessage(
            self::NOTIFICATION_ID,
            NotificationType::QUEST_CALLOUT,
            'Corridor walk is open now',
            'Corridor walk is open now — could you run it?',
            self::QUEST_ID,
            'https://monad.dubec.dev/d/monad03',
            ['tok-a'],
        );

        $payload = (new FcmPayloadBuilder())->build($message, 'tok-a')->jsonSerialize();

        self::assertSame('tok-a', $payload['token']);
        self::assertSame(['title' => 'Corridor walk is open now', 'body' => 'Corridor walk is open now — could you run it?'], $payload['notification']);
        self::assertSame([
            'notification_id' => self::NOTIFICATION_ID,
            'quest_id' => self::QUEST_ID,
            'deep_link' => 'https://monad.dubec.dev/d/monad03',
        ], $payload['data']);
        foreach ($payload['data'] as $value) {
            self::assertIsString($value, 'FCM data values are strings');
        }
        self::assertSame('quest_callouts', $payload['android']['notification']['channel_id']);
        self::assertArrayNotHasKey('apns', $payload, 'APNs is left at the library defaults');
    }

    public function testGeneralOmitsAbsentKeysAndUsesTheMessagesChannel(): void
    {
        $message = new PushMessage(self::NOTIFICATION_ID, NotificationType::GENERAL, 'Lab night', 'Doors at 18:00.', null, null, ['tok-b']);

        $payload = (new FcmPayloadBuilder())->build($message, 'tok-b')->jsonSerialize();

        self::assertSame(['notification_id' => self::NOTIFICATION_ID], $payload['data']);
        self::assertSame('messages', $payload['android']['notification']['channel_id']);
        self::assertSame('Lab night', $payload['notification']['title']);
        self::assertSame('tok-b', $payload['token']);
    }

    public function testOneCloudMessagePerTokenSharesTheContent(): void
    {
        $message = new PushMessage(self::NOTIFICATION_ID, NotificationType::GENERAL, 'T', 'B', null, null, ['t1', 't2']);
        $builder = new FcmPayloadBuilder();

        $first = $builder->build($message, 't1')->jsonSerialize();
        $second = $builder->build($message, 't2')->jsonSerialize();

        self::assertSame('t1', $first['token']);
        self::assertSame('t2', $second['token']);
        unset($first['token'], $second['token']);
        self::assertSame($first, $second);
    }
}
