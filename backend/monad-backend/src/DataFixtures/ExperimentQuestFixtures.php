<?php

namespace App\DataFixtures;

use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Entity\User;
use App\Enum\QuestStepType;
use App\Enum\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Measurement quests — the schedule engine used as an experiment runner.
 *
 * These are not games. A quest here is the *script an instrumented phone follows*
 * during an IP-106 capture, and its shape comes straight from the EXP-C1 design
 * study:
 *
 *  - The analysis unit is a **take**: one arrangement of one occupancy level for one
 *    time window. Each `wait` step is one take, so the quest's step timeline is the
 *    take timeline and `quest_step_completions.started_at/completed_at` are the take
 *    boundaries.
 *  - Recording longer buys almost nothing — windows inside one arrangement are one
 *    sample measured repeatedly. Power comes from *distinct arrangements*, so these
 *    quests use many short takes with a re-arrange prompt between them rather than
 *    one long recording.
 *  - Takes are 30 s, the minimum continuous capture IP-106 addendum A3 requires for
 *    the gamma-sweep to be reconstructible.
 *  - `scan_qr` steps bracket the run: a QR scan is an unambiguous device-side
 *    timestamp at a surveyed position, which is what makes phone labels and node CSI
 *    alignable afterwards.
 *
 * Loaded in the `experiment` group so a deployment can seed the open-day quests and
 * the measurement quests independently:
 *
 *     php bin/console doctrine:fixtures:load --group=experiment --append
 */
class ExperimentQuestFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /** Take length in seconds — IP-106 addendum A3. */
    private const TAKE_SECONDS = 30;

    /**
     * Block length for the walk-through ABBA contrast (EXP-LIB-02). Longer than
     * TAKE_SECONDS on purpose: this block's estimand is a variance/Doppler contrast
     * over a whole block, not a staged occupancy take, and 3 min x 12 blocks fits the
     * 45 m `walk-abba` csid profile with slack.
     */
    private const WALK_BLOCK_SECONDS = 180;

    public static function getGroups(): array
    {
        return ['experiment'];
    }

    /**
     * Declared so a full load orders this after the users exist. UserFixtures is idempotent, so
     * pulling it in during an `--append` run against a populated database is a no-op rather than a
     * unique-key collision.
     */
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // Looked up rather than declared as a fixture dependency: this group is meant
        // to be *appended* to a database that already has users (including a live
        // deployment), and re-running UserFixtures there would collide on the unique
        // e-mail. If no admin exists yet the fixture creates one so a bare database
        // still seeds in a single command.
        $author = $this->resolveAuthor($manager);

        $manager->persist($this->coreSession($author));
        $manager->persist($this->stillBlock($author));
        $manager->persist($this->emptyRoomBlock($author));
        $manager->persist($this->dryRun($author));
        $manager->persist($this->roomScan($author));
        $manager->persist($this->uwbSurvey($author));
        $manager->persist($this->bleRecalibration($author));
        $manager->persist($this->dualBand($author));
        $manager->persist($this->walkAbba($author));
        $manager->flush();
    }

    /**
     * EXP-C1 S1 — the core session. Six occupancy levels are staged by the operator;
     * the phone's job is to illuminate, witness and mark the take boundaries.
     */
    private function coreSession(User $author): Quest
    {
        $quest = $this->newQuest(
            $author,
            'EXP-C1 · Core session (occupancy 0–5)',
            'Staged occupancy sweep with re-randomised arrangements. Join the experiment AP, then '
            . 'hold still through each 30-second take. The operator will change the arrangement '
            . 'between takes — that change is the measurement, so do not skip it.',
            points: 0.0,
            minutes: 25,
        );

        $order = 0;
        $quest->addStep($this->step($order++, QuestStepType::START, 'Briefing', [
            // Read by the app to decide whether this run is also an illuminator session.
            'ap_id' => 'exp-ap-5g',
            'profile_id' => 'steady-200hz',
            'site_ref' => 'fiit/floor2/lab',
            'description' => "This is a measurement run, not a game.\n\n"
                . "1. Keep the phone in your pocket, screen off, once the run starts.\n"
                . "2. Stand where the operator places you and stay there for the whole take.\n"
                . "3. Between takes the operator will move everyone — that is deliberate.\n\n"
                . 'Nothing is recorded about you personally; the dataset carries a pseudonym.',
        ]));

        $quest->addStep($this->step($order++, QuestStepType::CONNECT_TO_AP, 'Join the experiment AP', [
            'ap_id' => 'exp-ap-5g',
            'description' => 'The phone joins the experiment access point and pins its socket to that '
                . 'interface. If pinning fails the run aborts — an unpinned socket sends the sounding '
                . 'traffic over cellular, where no receiver can hear it.',
        ]));

        $quest->addStep($this->step($order++, QuestStepType::SCAN_QR, 'Opening clock sync', [
            'qr_code_id' => 'room-marker-a',
            'expected_value' => 'MONAD-SYNC-A',
            'description' => 'Scan the room marker. This stamps a device-side timestamp at a surveyed '
                . 'position so the phone and the capture nodes can be aligned afterwards.',
        ]));

        // Two arrangements at every occupancy level 0..5. EXP-C1 recommends >=10
        // arrangements per level; a single quest run contributes two, so the level is
        // reached by repeating the quest with different groupings rather than by
        // making any one run longer.
        for ($occupancy = 0; $occupancy <= 5; $occupancy++) {
            foreach (['a', 'b'] as $variant) {
                $quest->addStep($this->step(
                    $order++,
                    QuestStepType::WAIT,
                    sprintf('Take · occupancy %d · arrangement %s', $occupancy, strtoupper($variant)),
                    [
                        'timeout_seconds' => self::TAKE_SECONDS,
                        'occupancy_count' => $occupancy,
                        'arrangement_id' => sprintf('o%d-%s', $occupancy, $variant),
                        'posture' => 'walking',
                        'description' => $occupancy === 0
                            ? 'Empty room. Everyone steps outside the doorway. This take calibrates the '
                              . 'detection threshold every later take is measured against.'
                            : sprintf(
                                '%d %s in the room. Hold position for %d seconds.',
                                $occupancy,
                                $occupancy === 1 ? 'person' : 'people',
                                self::TAKE_SECONDS
                            ),
                    ]
                ));
            }
        }

        $quest->addStep($this->step($order++, QuestStepType::SCAN_QR, 'Closing clock sync', [
            'qr_code_id' => 'room-marker-a',
            'expected_value' => 'MONAD-SYNC-A',
            'description' => 'Scan the same marker again. Two sync points bracket the run, so clock '
                . 'skew across the session is observable rather than assumed.',
        ]));

        $quest->addStep($this->step($order, QuestStepType::FINISH, 'Session complete', [
            'description' => 'Recording stops and the session uploads. Local data is released only '
                . 'after every artefact is acknowledged, so a failed upload keeps the data.',
        ]));

        return $quest;
    }

    /**
     * EXP-C1 S3 — the still block. The regime no public dataset covers: WiMANS
     * collapses to 12 windows at occupancy >= 2 when everyone is stationary, so this
     * capture is the only source of stationary crowds at graded occupancy.
     */
    private function stillBlock(User $author): Quest
    {
        $quest = $this->newQuest(
            $author,
            'EXP-C1 · Still-crowd block (seated)',
            'Seated, stationary occupancy. Longer takes, because a still crowd shows up only in the '
            . 'temporal fine-structure and needs enough seconds to resolve it.',
            points: 0.0,
            minutes: 20,
        );

        $order = 0;
        $quest->addStep($this->step($order++, QuestStepType::START, 'Briefing', [
            'ap_id' => 'exp-ap-5g',
            'profile_id' => 'steady-200hz',
            'site_ref' => 'fiit/floor2/lab',
            'description' => "Sit down and stay still. Breathing and small fidgets are the signal — "
                . "you do not need to freeze, just stay seated and stop moving around.",
        ]));

        $quest->addStep($this->step($order++, QuestStepType::CONNECT_TO_AP, 'Join the experiment AP', [
            'ap_id' => 'exp-ap-5g',
            'description' => 'Associate and pin the socket before any take begins.',
        ]));

        // 51 s windows — the length named in the count-signal-in-temporal-doppler gate.
        foreach ([0, 2, 4, 5] as $occupancy) {
            $quest->addStep($this->step(
                $order++,
                QuestStepType::WAIT,
                sprintf('Seated take · occupancy %d', $occupancy),
                [
                    'timeout_seconds' => 51,
                    'occupancy_count' => $occupancy,
                    'arrangement_id' => sprintf('still-o%d', $occupancy),
                    'posture' => 'seated',
                    'description' => $occupancy === 0
                        ? 'Empty room, chairs in place.'
                        : sprintf('%d seated, still, for 51 seconds.', $occupancy),
                ]
            ));
        }

        $quest->addStep($this->step($order, QuestStepType::FINISH, 'Session complete', [
            'description' => 'Stationary-crowd block recorded.',
        ]));

        return $quest;
    }

    /**
     * EXP-C1 S2 — empty-room block. Not warm-up: the only spike-count estimator that
     * worked on real CSI calibrates its per-rank thresholds on held-out count = 0
     * windows, so this is measurement and is sized to be split in half.
     */
    private function emptyRoomBlock(User $author): Quest
    {
        $quest = $this->newQuest(
            $author,
            'EXP-C1 · Empty-room baseline',
            'Nobody in the room. This block sets the detection threshold every occupied take is '
            . 'scored against, so it is measurement rather than warm-up.',
            points: 0.0,
            minutes: 10,
        );

        $order = 0;
        $quest->addStep($this->step($order++, QuestStepType::START, 'Briefing', [
            'ap_id' => 'exp-ap-5g',
            'profile_id' => 'steady-200hz',
            'site_ref' => 'fiit/floor2/lab',
            'description' => 'Place the phone on the marked spot, leave the room, and close the door. '
                . 'Do not walk past the doorway until the block finishes.',
        ]));

        $quest->addStep($this->step($order++, QuestStepType::CONNECT_TO_AP, 'Join the experiment AP', [
            'ap_id' => 'exp-ap-5g',
            'description' => 'Associate and pin before the baseline begins.',
        ]));

        for ($i = 1; $i <= 8; $i++) {
            $quest->addStep($this->step(
                $order++,
                QuestStepType::WAIT,
                sprintf('Baseline take %d of 8', $i),
                [
                    'timeout_seconds' => self::TAKE_SECONDS,
                    'occupancy_count' => 0,
                    'arrangement_id' => sprintf('empty-%02d', $i),
                    'posture' => 'empty',
                    'description' => 'Empty room. Eight takes so the set can be split — half to set the '
                        . 'thresholds, half to score against them.',
                ]
            ));
        }

        $quest->addStep($this->step($order, QuestStepType::FINISH, 'Baseline complete', [
            'description' => 'Threshold-calibration block recorded.',
        ]));

        return $quest;
    }

    /**
     * EXP-C1 rehearsal — the same take structure with the radio deliberately left out.
     *
     * Names no AP and no traffic profile, so the run stays a witness-only session and never
     * attempts association. That makes it the one measurement quest that works on an emulator, a
     * desk, or a room with no experiment AP yet — which is exactly what the pre-arrival rehearsal
     * needs: walk the whole step sequence, confirm the timings and the wording, and find the
     * failures before anyone is standing in the room waiting.
     */
    private function dryRun(User $author): Quest
    {
        $quest = $this->newQuest(
            $author,
            'EXP-C1 · Dry run (no radio)',
            'Rehearsal of the core session with the radio left out: no access point, no illumination, '
            . 'no association. Use it to walk the step sequence and check the instructions read '
            . 'correctly before a real capture.',
            points: 0.0,
            minutes: 5,
        );

        $order = 0;
        $quest->addStep($this->step($order++, QuestStepType::START, 'Rehearsal briefing', [
            // No ap_id / profile_id on purpose — that is what keeps this run radio-free.
            'site_ref' => 'fiit/floor2/lab',
            'description' => "Dry run: nothing is transmitted and no access point is joined.\n\n"
                . "Walk the steps as if it were the real thing and check that every instruction "
                . "makes sense standing in the room, not at a desk.",
        ]));

        foreach ([[0, 'a'], [1, 'a'], [2, 'a']] as [$occupancy, $variant]) {
            $quest->addStep($this->step(
                $order++,
                QuestStepType::WAIT,
                sprintf('Take · occupancy %d · arrangement %s', $occupancy, strtoupper($variant)),
                [
                    'timeout_seconds' => 15,
                    'occupancy_count' => $occupancy,
                    'arrangement_id' => sprintf('dry-o%d-%s', $occupancy, $variant),
                    'posture' => 'walking',
                    'description' => $occupancy === 0
                        ? 'Empty room. Shortened to 15 s for the rehearsal.'
                        : sprintf('%d %s in the room. Hold position (15 s in rehearsal).',
                            $occupancy, $occupancy === 1 ? 'person' : 'people'),
                ]
            ));
        }

        $quest->addStep($this->step($order, QuestStepType::FINISH, 'Rehearsal complete', [
            'description' => 'If every step read clearly and the timings felt right, the real '
                . 'session is ready to run.',
        ]));

        return $quest;
    }

    /**
     * Room geometry capture — LiDAR only.
     *
     * Requires `lidar.mesh`, which today only ARKit devices can honestly claim, so this quest is
     * simply absent from the catalogue on every other handset. The output feeds the PostGIS site
     * model and the ray-traced channel simulator: a real room instead of a uniform-material box,
     * which is the fidelity gap [[wall-material-propagation-bias]] is about.
     */
    private function roomScan(User $author): Quest
    {
        $quest = $this->newQuest(
            $author,
            'EXP-C1 · Room geometry scan (LiDAR)',
            'Walk the room slowly with the phone held up, covering walls, floor and large furniture. '
            . 'The mesh becomes the simulator\'s floor model, so cover what a radio would see.',
            points: 0.0,
            minutes: 8,
        );
        $quest->setRequiredCapabilities(['lidar.mesh']);

        $order = 0;
        $quest->addStep($this->step($order++, QuestStepType::START, 'Before you scan', [
            'site_ref' => 'fiit/floor2/lab',
            'description' => "Hold the phone at chest height and walk the perimeter first, then sweep "
                . "across the middle. Slow and steady beats fast and complete.",
        ]));
        $quest->addStep($this->step($order++, QuestStepType::SENSOR_CAPTURE, 'Scan the room', [
            'module' => 'room-scan',
            'scan_label' => 'fiit-floor2-lab',
            'description' => 'Capture the room mesh. The step fails rather than completing if the '
                . 'scan returns no geometry — an empty mesh is worse than a missing one.',
        ]));
        $quest->addStep($this->step($order, QuestStepType::FINISH, 'Scan complete', [
            'description' => 'The mesh uploads with the session and is registered against the site.',
        ]));

        return $quest;
    }

    /**
     * UWB anchor survey — decimetre position labels.
     *
     * Requires `uwb.ranging`. Where a QR fix gives one accurate position at one moment, ranging to
     * surveyed anchors gives a continuous track — which is what turns a fingerprint labelled "in
     * the lab" into one labelled with a coordinate.
     */
    private function uwbSurvey(User $author): Quest
    {
        $quest = $this->newQuest(
            $author,
            'EXP-C1 · UWB position survey',
            'Walk a slow loop of the room while the phone ranges against the surveyed anchors. '
            . 'Produces a continuous position track to label captures with.',
            points: 0.0,
            minutes: 6,
        );
        $quest->setRequiredCapabilities(['uwb.ranging']);

        $order = 0;
        $quest->addStep($this->step($order++, QuestStepType::START, 'Before you walk', [
            'site_ref' => 'fiit/floor2/lab',
            'description' => "Check the anchors are powered and ranging before you start. With no "
                . "anchor in earshot the capture fails rather than recording an empty track.",
        ]));
        $quest->addStep($this->step($order++, QuestStepType::SENSOR_CAPTURE, 'Range the anchors', [
            'module' => 'uwb-range',
            'uwb_anchors' => ['A0:01', 'A0:02', 'A0:03'],
            'description' => 'Ranges are timestamped on the same monotonic clock as the radio '
                . 'streams, so the track aligns with CSI windows without a second sync story.',
        ]));
        $quest->addStep($this->step($order, QuestStepType::FINISH, 'Survey complete', [
            'description' => 'The range log uploads alongside the session streams.',
        ]));

        return $quest;
    }

    /**
     * The thesis's headline claim, as a repeatable field pass.
     *
     * [[ble-periodic-calibration]] says re-anchoring a CSI counter against a BLE reference stops
     * its accuracy rotting, and that no published work compares periodic against one-shot
     * calibration. Testing that needs *calendar time*, not more people — so this quest is
     * deliberately short and designed to be re-run every week or two by whoever is nearest, which
     * is what makes the drift curve measurable at all.
     *
     * Five anchor points, because the powered result showed refit-on-5-anchors recovers the
     * penalty where two does not.
     */
    private function bleRecalibration(User $author): Quest
    {
        $quest = $this->newQuest(
            $author,
            'EXP-C1 · BLE recalibration pass (weekly)',
            'A short anchored pass to re-tune the counter against a known headcount. Run it every '
            . 'week or two on the same room — the value is in the repetition, not in any one run.',
            points: 0.0,
            minutes: 8,
        );

        $order = 0;
        $quest->addStep($this->step($order++, QuestStepType::START, 'Why this is worth 8 minutes', [
            'ap_id' => 'exp-ap-5g',
            'profile_id' => 'steady-200hz',
            'site_ref' => 'fiit/floor2/lab',
            'description' => "A WiFi counter that is accurate in September is quietly wrong by "
                . "December: desks move, an access point gets replaced, nothing announces it.\n\n"
                . "This pass re-anchors it against a known headcount. Repeating it is the "
                . "measurement — one pass tells us nothing, ten passes over a term tell us whether "
                . "periodic re-anchoring actually beats calibrating once and hoping.",
        ]));
        $quest->addStep($this->step($order++, QuestStepType::CONNECT_TO_AP, 'Join the experiment AP', [
            'ap_id' => 'exp-ap-5g',
            'description' => 'Associate and pin before any anchor is recorded.',
        ]));
        $quest->addStep($this->step($order++, QuestStepType::SCAN_QR, 'Mark the room', [
            'qr_code_id' => 'room-marker-a',
            'expected_value' => 'MONAD-SYNC-A',
            'description' => 'Fixes position and clock so this pass is comparable with the last one.',
        ]));

        foreach ([0, 1, 2, 3, 5] as $index => $occupancy) {
            $quest->addStep($this->step(
                $order++,
                QuestStepType::WAIT,
                sprintf('Anchor %d · occupancy %d', $index + 1, $occupancy),
                [
                    'timeout_seconds' => 30,
                    'occupancy_count' => $occupancy,
                    'arrangement_id' => sprintf('recal-a%d', $index + 1),
                    'posture' => 'walking',
                    'description' => 0 === $occupancy
                        ? 'Empty room — the reference every other anchor is measured against.'
                        : sprintf('%d in the room, moving normally. Hold for 30 s.', $occupancy),
                ]
            ));
        }

        $quest->addStep($this->step($order, QuestStepType::FINISH, 'Pass complete', [
            'description' => 'Five anchors recorded. Two is not enough to recover the drift '
                . 'penalty; five is what the powered result needed.',
        ]));

        return $quest;
    }

    /**
     * Band A/B at matched hardware and occupancy.
     *
     * [[band-5ghz-occupancy-discriminability]] became more interesting on 2026-08-03, not less:
     * the 2.4 GHz band, where the established variance proxy is at chance (R² 0.05), is exactly
     * where the spectral mode count recovers the signal (R² 0.35). So "5 GHz is the better sensing
     * band" looks like a property of the *feature*, not the band — and that only holds up if both
     * bands are recorded on the same nodes, in the same room, at the same occupancy.
     *
     * The two arms are back-to-back on purpose: run sequentially on different days and the
     * comparison is confounded with everything that changed in between.
     */
    private function dualBand(User $author): Quest
    {
        $quest = $this->newQuest(
            $author,
            'EXP-C1 · Dual-band A/B (2.4 vs 5 GHz)',
            'The same occupancy staged twice, once per band, back to back. Tests whether the band '
            . 'or the feature is what makes a room readable.',
            points: 0.0,
            minutes: 18,
        );

        $order = 0;
        $quest->addStep($this->step($order++, QuestStepType::START, 'What this settles', [
            'ap_id' => 'exp-ap-5g',
            'profile_id' => 'steady-200hz',
            'site_ref' => 'fiit/floor2/lab',
            'description' => "Received wisdom says 5 GHz reads a room better than 2.4 GHz.\n\n"
                . "Re-analysis suggests that is true of the *variance* feature and false of the "
                . "spectral one — at 2.4 GHz variance is near chance while mode counting still "
                . "works. Staging the same occupancy on both bands back to back is the only way to "
                . "tell a band effect from a feature effect.",
        ]));

        foreach ([['exp-ap-5g', '5 GHz'], ['exp-ap-24', '2.4 GHz']] as [$apId, $bandLabel]) {
            $quest->addStep($this->step($order++, QuestStepType::CONNECT_TO_AP,
                sprintf('Switch to %s', $bandLabel), [
                    'ap_id' => $apId,
                    'description' => sprintf('Join the %s access point and pin the socket.', $bandLabel),
                ]));

            foreach ([0, 2, 4] as $occupancy) {
                $quest->addStep($this->step(
                    $order++,
                    QuestStepType::WAIT,
                    sprintf('%s · occupancy %d', $bandLabel, $occupancy),
                    [
                        'timeout_seconds' => 30,
                        'occupancy_count' => $occupancy,
                        'arrangement_id' => sprintf('band-%s-o%d', str_replace(' ', '', $bandLabel), $occupancy),
                        'posture' => 'walking',
                        'band' => $bandLabel,
                        'description' => 0 === $occupancy
                            ? sprintf('Empty room on %s.', $bandLabel)
                            : sprintf('%d in the room on %s — same people, same positions as the other band.',
                                $occupancy, $bandLabel),
                    ]
                ));
            }
        }

        $quest->addStep($this->step($order, QuestStepType::FINISH, 'Both bands recorded', [
            'description' => 'Matched occupancy on both bands, same room, same hour — the only '
                . 'comparison that can separate the band from the feature.',
        ]));

        return $quest;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * EXP-LIB-02 — walk-through ABBA, the occupied arm paired with the EXP-LIB-01
     * empty-library baseline (2026-08-12, 4.87 M records, 5 observers, 12 h).
     *
     * WHY THIS QUEST NAMES NO AP. Every other quest in this file carries
     * `ap_id => 'exp-ap-5g'` and a CONNECT_TO_AP step. That design is dead: 5 GHz AP
     * mode is impossible on this hardware (Intel LAR firmware limit — it crashes the
     * iax backport), and the fleet moved to monitor-mode broadcast injection on
     * 2.4 GHz ch11. There is nothing for the phone to associate to, so a
     * CONNECT_TO_AP step here would block the run on an association that cannot
     * happen. The phone is a WITNESS and a LABELLER in this quest, not an
     * illuminator — the illumination is monad01 running `csid@illum-walk`.
     *
     * The pattern is a true ABBA (A B B A, three times) rather than a simple
     * alternation: it balances any linear time trend across the two conditions, which
     * matters because the radios have a measured ~30 min warm-up transient and the
     * whole block is 36 min long. Analyse PER PAIR, not pooled.
     *
     * A = empty (operator outside the room), B = walking.
     */
    private function walkAbba(User $author): Quest
    {
        $quest = $this->newQuest(
            $author,
            'EXP-LIB-02 · Walk-through ABBA',
            'One person walks an otherwise-empty library while the fleet watches. Paired with the '
            . 'EXP-LIB-01 empty-night baseline, this is the first first-party motion-vs-static '
            . 'contrast in the project. The phone marks block boundaries; it does not illuminate.',
            points: 0.0,
            minutes: 45,
        );

        $order = 0;
        $quest->addStep($this->step($order++, QuestStepType::START, 'Briefing', [
            // No ap_id ON PURPOSE — see the docblock. Monitor-mode injection has no AP.
            'profile_id' => 'walk-abba',
            'site_ref' => 'fiit/library',
            'description' => "This is a measurement run, not a game.\n\n"
                . 'The library is empty apart from you. When a block says WALK, walk a steady loop '
                . 'through the space at an ordinary pace. When a block says EMPTY, leave the room '
                . "and stay out of the doorway until it ends.\n\n"
                . 'Do not change anything else between blocks — same route, same pace, same doors.',
        ]));

        $quest->addStep($this->step($order++, QuestStepType::SCAN_QR, 'Opening clock sync', [
            'qr_code_id' => 'library-marker-a',
            'expected_value' => 'MONAD-SYNC-LIB-A',
            'description' => 'Scan the room marker. This stamps a device-side timestamp at a known '
                . 'position, which is what makes the phone labels and the node CSI alignable '
                . 'afterwards. Without it the blocks are unlabelled.',
        ]));

        // A B B A, three times — 12 blocks, 6 per condition, order-balanced.
        $pattern = ['A', 'B', 'B', 'A', 'A', 'B', 'B', 'A', 'A', 'B', 'B', 'A'];
        $emptyIdx = 0;
        $walkIdx = 0;

        foreach ($pattern as $i => $condition) {
            $isWalk = $condition === 'B';
            $label = $isWalk ? ++$walkIdx : ++$emptyIdx;

            $quest->addStep($this->step(
                $order++,
                QuestStepType::WAIT,
                sprintf('%s — block %d of 12', $isWalk ? 'WALK' : 'EMPTY', $i + 1),
                [
                    'timeout_seconds' => self::WALK_BLOCK_SECONDS,
                    'occupancy_count' => $isWalk ? 1 : 0,
                    'arrangement_id' => sprintf('%s-%02d', $isWalk ? 'walk' : 'empty', $label),
                    'posture' => $isWalk ? 'walking' : 'empty',
                    'description' => $isWalk
                        ? 'Walk a steady loop through the space for three minutes. Ordinary pace, '
                            . 'no pauses, do not stand still next to a node.'
                        : 'Leave the room and close the door. Stay clear of the doorway until the '
                            . 'block ends — a person in the doorway is not an empty room.',
                ]
            ));
        }

        $quest->addStep($this->step($order++, QuestStepType::SCAN_QR, 'Closing clock sync', [
            'qr_code_id' => 'library-marker-a',
            'expected_value' => 'MONAD-SYNC-LIB-A',
            'description' => 'Scan the same marker again. Two sync points bracket the run, so clock '
                . 'drift over the session is measurable rather than assumed.',
        ]));

        $quest->addStep($this->step($order, QuestStepType::FINISH, 'Session complete', [
            'description' => 'Walk-through contrast recorded. The occupied arm of the EXP-LIB-01 pair.',
        ]));

        return $quest;
    }

    private function resolveAuthor(ObjectManager $manager): User
    {
        $repo = $manager->getRepository(User::class);

        $existing = $repo->findOneBy(['email' => 'admin@fiit.stuba.sk']);
        if ($existing instanceof User) {
            return $existing;
        }

        foreach ($repo->findAll() as $candidate) {
            if ($candidate instanceof User && in_array(UserRole::SUPERADMIN->value, $candidate->getRoles(), true)) {
                return $candidate;
            }
        }

        $admin = new User();
        $admin->setEmail('admin@fiit.stuba.sk');
        $admin->setName('FIIT Admin');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $admin->grantRole(UserRole::SUPERADMIN);
        $manager->persist($admin);
        $manager->flush();

        return $admin;
    }

    private function newQuest(User $author, string $name, string $description, float $points, int $minutes): Quest
    {
        $quest = new Quest();
        $quest->setName($name);
        $quest->setDescription($description);
        // Relative windows on purpose: the original fixtures hardcoded 2025 dates and
        // silently vanished from /api/quests on 2026-01-01.
        $quest->setAvailableFrom(new \DateTime('-1 day'));
        $quest->setAvailableTo(new \DateTime('+2 years'));
        $quest->setPoints($points);
        $quest->setEstimatedDuration($minutes);
        $quest->setCreatedBy($author);

        return $quest;
    }

    private function step(int $order, QuestStepType $type, string $name, array $config): QuestStep
    {
        $step = new QuestStep();
        $step->setName($name);
        $step->setType($type);
        $step->setOrder($order);
        $step->setConfig($config);

        return $step;
    }
}
