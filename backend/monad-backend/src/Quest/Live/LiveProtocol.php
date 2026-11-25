<?php

declare(strict_types=1);

namespace App\Quest\Live;

/**
 * The live-quest wire contract, named once.
 *
 * A `live` step is a step whose screen the server drives. The phone sends what happened (events),
 * the server runs one engine and answers with a view: a list of widgets to draw. A new quest
 * mechanic is a new engine on the server and needs no app release, as long as it is made of widgets
 * the app already knows ({@see LiveView}).
 *
 * The app's half is `quests/live` in monad-app. A new widget type or a changed meaning is a new
 * protocol number AND a new capability token, so a build that predates it is never served the step.
 */
final class LiveProtocol
{
    public const PROTOCOL = 'monad-live/v1';

    /** Claimed by every app build that can run a live step, enforced on listing and on start. */
    public const CAPABILITY = 'quest.live.v1';

    private function __construct()
    {
    }
}
