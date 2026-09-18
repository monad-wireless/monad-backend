<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Device;
use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Enum\QuestStepType;
use App\Enum\RecurrenceScope;
use App\Form\QuestHeaderData;
use App\Quest\QuestSpecMapper;
use PHPUnit\Framework\TestCase;

/**
 * The quest builder's header, in and out of the entity (IP-157 Phase 2).
 *
 * Three fields are reshaped on the way to the form and this file pins all three: `recurrence` is
 * one JSON column and two form fields (with "unlimited" as the absent policy, which is what every
 * quest did before IP-128), `route_policy` is a mode plus a textarea, and the four session
 * features live on the start STEP's config while being edited beside the header.
 *
 * Plain unit tests: QuestHeaderData is a data object with two mapping methods and no services.
 */
final class QuestHeaderDataTest extends TestCase
{
    // ── recurrence ──────────────────────────────────────────────────────────────────────────

    public function testUnlimitedIsNoPolicyAtAll(): void
    {
        $data = new QuestHeaderData();
        $data->recurrenceScope = QuestHeaderData::SCOPE_UNLIMITED;
        $data->recurrenceCooldownSeconds = 3600;

        // The cooldown is kept in the form but means nothing without a scope, so it is not stored.
        self::assertNull($data->recurrence());
    }

    public function testAScopeAndACooldownBecomeTheStoredJson(): void
    {
        $data = new QuestHeaderData();
        $data->recurrenceScope = RecurrenceScope::PER_DEVICE->value;
        $data->recurrenceCooldownSeconds = 86400;

        self::assertSame(['scope' => 'per_device', 'cooldown_seconds' => 86400], $data->recurrence());
    }

    public function testAMissingCooldownIsZeroNotNull(): void
    {
        $data = new QuestHeaderData();
        $data->recurrenceScope = RecurrenceScope::PER_QUEST->value;
        $data->recurrenceCooldownSeconds = null;

        self::assertSame(['scope' => 'per_quest', 'cooldown_seconds' => 0], $data->recurrence());
    }

    public function testANegativeCooldownIsClampedRatherThanThrowing(): void
    {
        $data = new QuestHeaderData();
        $data->recurrenceScope = RecurrenceScope::PER_DEVICE->value;
        $data->recurrenceCooldownSeconds = -60;

        self::assertSame(['scope' => 'per_device', 'cooldown_seconds' => 0], $data->recurrence());
    }

    public function testRecurrenceRoundTripsThroughTheEntity(): void
    {
        $quest = (new Quest())->setRecurrence(['scope' => 'per_device', 'cooldown_seconds' => 900]);

        $data = QuestHeaderData::fromQuest($quest);
        self::assertSame('per_device', $data->recurrenceScope);
        self::assertSame(900, $data->recurrenceCooldownSeconds);

        $back = new Quest();
        $data->applyTo($back);
        self::assertSame(['scope' => 'per_device', 'cooldown_seconds' => 900], $back->getRecurrence());
    }

    public function testAQuestWithNoPolicyReadsBackAsUnlimited(): void
    {
        $data = QuestHeaderData::fromQuest(new Quest());

        self::assertSame(QuestHeaderData::SCOPE_UNLIMITED, $data->recurrenceScope);
        self::assertNull($data->recurrenceCooldownSeconds);
    }

    public function testAMalformedStoredPolicyDegradesToUnlimited(): void
    {
        // RecurrencePolicy::fromArray() returns null for garbage rather than throwing; the form
        // must show the same thing the engine enforces, which is no extra gate.
        $quest = (new Quest())->setRecurrence(['scope' => 'per_fortnight', 'cooldown_seconds' => 10]);

        self::assertSame(QuestHeaderData::SCOPE_UNLIMITED, QuestHeaderData::fromQuest($quest)->recurrenceScope);
    }

    // ── capabilities ────────────────────────────────────────────────────────────────────────

    public function testOnlyTokensTheAppSendsSurviveTheRoundTrip(): void
    {
        $quest = (new Quest())->setRequiredCapabilities(['ble.advertise', 'telepathy', 'camera.qr']);

        self::assertSame(['ble.advertise', 'camera.qr'], QuestHeaderData::fromQuest($quest)->requiredCapabilities);
    }

    public function testDerivedCapabilitiesAreAddedToTheTickedOnes(): void
    {
        $data = new QuestHeaderData();
        $data->name = 'Q';
        $data->description = 'D';
        $data->requiredCapabilities = ['background.residency'];

        $quest = new Quest();
        $data->applyTo($quest, ['ble.advertise', 'camera.qr']);

        self::assertSame(['background.residency', 'ble.advertise', 'camera.qr'], $quest->getRequiredCapabilities());
    }

    // ── route policy ────────────────────────────────────────────────────────────────────────

    public function testFixedModeStoresNoPolicyWhateverTheTextareaHolds(): void
    {
        $data = new QuestHeaderData();
        $data->routeMode = QuestHeaderData::ROUTE_FIXED;
        $data->routes = "MONAD-FP-15, monad02\n";

        self::assertSame([], $data->parsedRoutes());

        $quest = (new Quest())->setRoutePolicy(['mode' => 'pool', 'routes' => [['MONAD-FP-07']]]);
        $data->name = 'Q';
        $data->description = 'D';
        $data->applyTo($quest);
        self::assertNull($quest->getRoutePolicy());
    }

    public function testThePoolTextareaIsOneRoutePerLineCommaSeparated(): void
    {
        $data = new QuestHeaderData();
        $data->routeMode = QuestHeaderData::ROUTE_POOL;
        $data->routes = "MONAD-FP-15, monad02 , MONAD-FP-07\r\n\n  \nmonad07,MONAD-FP-18\n";

        self::assertSame([
            ['MONAD-FP-15', 'monad02', 'MONAD-FP-07'],
            ['monad07', 'MONAD-FP-18'],
        ], $data->parsedRoutes());
    }

    public function testRoutesRoundTripThroughTheEntity(): void
    {
        $quest = (new Quest())->setRoutePolicy(['mode' => 'pool', 'routes' => [['a', 'b'], ['c']]]);

        $data = QuestHeaderData::fromQuest($quest);
        self::assertSame(QuestHeaderData::ROUTE_POOL, $data->routeMode);
        self::assertSame("a, b\nc", $data->routes);

        $back = new Quest();
        $data->name = 'Q';
        $data->description = 'D';
        $data->applyTo($back);
        self::assertSame([['a', 'b'], ['c']], QuestSpecMapper::routesOf($back->getRoutePolicy()));
    }

    // ── session features ────────────────────────────────────────────────────────────────────

    public function testFeaturesAreReadOffTheFirstStartStep(): void
    {
        $quest = new Quest();
        $quest->addStep((new QuestStep())->setName('Start')->setType(QuestStepType::START)->setOrder(0)
            ->setConfig(['features' => ['broadcast' => true, 'witness' => true]]));

        $data = QuestHeaderData::fromQuest($quest);

        self::assertTrue($data->featureBroadcast);
        self::assertTrue($data->featureWitness);
        self::assertFalse($data->featureTrack);
        self::assertFalse($data->featureIlluminator);
    }

    public function testEveryFlagIsWrittenExplicitlySoAnUntickedOneIsStoredAsFalse(): void
    {
        $data = new QuestHeaderData();
        $data->featureBroadcast = true;

        // Explicit false rather than an absent key: the app defaults everything to off, and a
        // config that says so is the difference between "chosen" and "never considered".
        self::assertSame(
            ['broadcast' => true, 'track' => false, 'witness' => false, 'illuminator' => false],
            $data->features(),
        );
    }

    public function testAQuestWithNoStartStepHasEveryFeatureOff(): void
    {
        $data = QuestHeaderData::fromQuest(new Quest());

        self::assertFalse($data->featureBroadcast);
        self::assertFalse($data->featureTrack);
        self::assertFalse($data->featureWitness);
        self::assertFalse($data->featureIlluminator);
    }

    // ── the rest of the header ──────────────────────────────────────────────────────────────

    public function testArmedDevicesAreReplacedNotAppended(): void
    {
        $one = (new Device())->setSlug('monad01');
        $two = (new Device())->setSlug('monad02');

        $quest = new Quest();
        $quest->addArmedDevice($one);

        $data = QuestHeaderData::fromQuest($quest);
        self::assertSame([$one], $data->armedDevices);

        $data->name = 'Q';
        $data->description = 'D';
        $data->armedDevices = [$two];
        $data->applyTo($quest);

        self::assertSame([$two], array_values($quest->getArmedDevices()->toArray()));
    }

    public function testABlankAvailableFromBecomesNowRatherThanNull(): void
    {
        $data = new QuestHeaderData();
        $data->name = 'Q';
        $data->description = 'D';
        $data->availableFrom = null;

        $quest = new Quest();
        $data->applyTo($quest);

        // The column is NOT NULL and the form requires it; this is the belt to that braces.
        self::assertNotNull($quest->getAvailableFrom());
        self::assertNull($quest->getAvailableTo());
    }

    public function testAnUnknownAudienceFallsBackToPublicOnTheEntity(): void
    {
        $data = new QuestHeaderData();
        $data->name = 'Q';
        $data->description = 'D';
        $data->audience = 'nobody';

        $quest = new Quest();
        $data->applyTo($quest);

        self::assertSame(Quest::AUDIENCE_PUBLIC, $quest->getAudience());
    }
}
