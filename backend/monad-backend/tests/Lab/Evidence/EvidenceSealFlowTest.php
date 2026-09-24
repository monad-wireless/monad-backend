<?php

declare(strict_types=1);

namespace App\Tests\Lab\Evidence;

use App\Entity\LabEvidenceManifest;
use App\Entity\LabReferenceReceipt;
use App\Entity\Quest;
use App\Entity\QuestEnrollment;
use App\Entity\QuestStep;
use App\Entity\QuestStepCompletion;
use App\Entity\User;
use App\Enum\QuestEnrollmentStatus;
use App\Enum\QuestStepCompletionStatus;
use App\Enum\QuestStepType;
use App\Lab\Contract\CanonicalJson;
use App\Lab\Evidence\ArtefactReader;
use App\Lab\Evidence\EvidenceSealService;
use App\Lab\Evidence\SealOutcome;
use App\Lab\Evidence\SweepReceiptService;
use App\Lab\Evidence\TooLargeToVerify;
use App\Repository\LabEvidenceManifestRepository;
use App\Repository\LabReferenceReceiptRepository;
use App\Repository\LabSessionRepository;
use App\Service\LabSessionRegister;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The evidence seal and the sweep receipts, against a real PostgreSQL (IP-162 §3).
 *
 * The object store is the one fake: a map of filename to bytes that plays the staged bucket. What
 * is under test is what SQL does to rows — one accepted seal per recording, identical retries
 * returning one receipt, a different digest refused as a conflict — and the reconciliation that
 * must reach the same status whichever of the completion and the upload arrives first.
 */
final class EvidenceSealFlowTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'ip162-seal-';
    private const QUEST_PREFIX = 'IP-162 seal test ';
    /** UUID-shaped, as the app mints recording ids; the contract requires it and the scrub filters on the prefix. */
    private const RECORDING = '1a162000-0000-4000-8000-000000000001';
    private const SWEEP = '5e000000-0000-4000-8000-000000000001';

    private EntityManagerInterface $em;
    private LabSessionRegister $register;
    private LabSessionRepository $sessions;
    private LabEvidenceManifestRepository $manifests;
    private LabReferenceReceiptRepository $receipts;
    private SweepReceiptService $receiptService;
    private FakeArtefactReader $bucket;
    private EvidenceSealService $seal;

    private User $participant;
    private User $stranger;
    private Quest $quest;
    private QuestEnrollment $enrollment;
    private QuestStepCompletion $sweepStep;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->register = $container->get(LabSessionRegister::class);
        $this->sessions = $container->get(LabSessionRepository::class);
        $this->manifests = $container->get(LabEvidenceManifestRepository::class);
        $this->receipts = $container->get(LabReferenceReceiptRepository::class);
        $this->receiptService = $container->get(SweepReceiptService::class);
        $this->bucket = new FakeArtefactReader();
        $this->seal = new EvidenceSealService(
            $this->em,
            $this->manifests,
            $this->sessions,
            $this->bucket,
            $this->receiptService,
            new NullLogger(),
        );
        $this->scrub();

        $this->config = json_decode(
            (string) file_get_contents(__DIR__ . '/../../fixtures/ip162/observe/valid/room-sweep.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['config'];

        $this->participant = $this->makeUser();
        $this->stranger = $this->makeUser();
        $this->quest = $this->makeQuest();
        [$this->enrollment, $this->sweepStep] = $this->enrol($this->participant);
        $this->registerRecording();
    }

    protected function tearDown(): void
    {
        $this->scrub();
        parent::tearDown();
        $this->em->close();
    }

    // ── the two arrival orders ──────────────────────────────────────────────────────────────

    public function testUploadFirstThenCompletionVerifies(): void
    {
        $outcome = $this->seal->seal($this->session(), $this->manifest(), $this->participant);
        self::assertSame(SealOutcome::VERIFIED, $outcome->status, json_encode($outcome->toArray()));
        self::assertSame(200, $outcome->httpStatus());

        $problems = $this->receiptService->recordCompletion($this->sweepStep, $this->summary());
        self::assertSame([], $problems);
        $this->em->flush();

        $receipt = $this->receipts->findForSweep(self::RECORDING, \Symfony\Component\Uid\Uuid::fromString(self::SWEEP));
        self::assertNotNull($receipt);
        self::assertSame(LabReferenceReceipt::STATUS_VERIFIED, $receipt->getStatus());
        self::assertSame($outcome->row?->getManifestSha256(), $receipt->getManifestSha256());

        // The observed digests joined the register's inventory.
        $this->em->clear();
        $artefacts = $this->sessions->find(self::RECORDING)?->getArtefacts() ?? [];
        self::assertSame(CanonicalJson::sha256Bytes($this->bucket->bytes['markers.tsv']), $artefacts['markers.tsv']['sha256'] ?? null);
    }

    public function testCompletionFirstThenUploadReachesTheSameStatus(): void
    {
        self::assertSame([], $this->receiptService->recordCompletion($this->sweepStep, $this->summary()));
        $this->em->flush();
        $pending = $this->receipts->findForSweep(self::RECORDING, \Symfony\Component\Uid\Uuid::fromString(self::SWEEP));
        self::assertSame(LabReferenceReceipt::STATUS_PENDING, $pending?->getStatus());
        self::assertSame(['manifest_not_sealed'], $pending?->getReasons());

        $outcome = $this->seal->seal($this->session(), $this->manifest(), $this->participant);
        self::assertSame(SealOutcome::VERIFIED, $outcome->status);
        $this->em->clear();
        $verified = $this->receipts->findForSweep(self::RECORDING, \Symfony\Component\Uid\Uuid::fromString(self::SWEEP));
        self::assertSame(LabReferenceReceipt::STATUS_VERIFIED, $verified?->getStatus());
    }

    // ── idempotency and conflict ────────────────────────────────────────────────────────────

    public function testAnIdenticalRetryReturnsTheSameReceiptAndADifferentDigestIsAConflict(): void
    {
        $manifest = $this->manifest();
        $first = $this->seal->seal($this->session(), $manifest, $this->participant);
        $again = $this->seal->seal($this->session(), $manifest, $this->participant);
        self::assertSame(SealOutcome::VERIFIED, $again->status);
        self::assertSame($first->row?->getManifestSha256(), $again->row?->getManifestSha256());
        self::assertCount(1, $this->manifests->findForRecording(self::RECORDING));

        $other = $manifest;
        $other['app_build_id'] = 'a-different-build';
        $conflict = $this->seal->seal($this->session(), $other, $this->participant);
        self::assertSame(SealOutcome::CONFLICT, $conflict->status);
        self::assertSame(409, $conflict->httpStatus());
        self::assertSame($first->row?->getManifestSha256(), $conflict->accepted?->getManifestSha256());
        $rows = $this->manifests->findForRecording(self::RECORDING);
        self::assertCount(2, $rows, 'the conflicting attempt is kept with its disposition');
        self::assertCount(1, array_filter($rows, static fn (LabEvidenceManifest $m) => $m->isAccepted()), 'one accepted seal per recording');
    }

    public function testAHashMismatchIsInvalidAndSealsNothing(): void
    {
        $manifest = $this->manifest();
        $manifest['artifacts'][0]['sha256'] = str_repeat('0', 64);
        $outcome = $this->seal->seal($this->session(), $manifest, $this->participant);
        self::assertSame(SealOutcome::INVALID, $outcome->status);
        self::assertSame(422, $outcome->httpStatus());
        self::assertContains('artifact_hash_mismatch:' . $manifest['artifacts'][0]['name'], $outcome->reasons);
        self::assertNull($this->manifests->findAccepted(self::RECORDING));
    }

    public function testAMissingArtefactIsPendingUntilItLandsThenVerifies(): void
    {
        // The app lists the sidecar in the manifest before uploading it (its bytes are final);
        // here the manifest names it and the bucket does not hold it yet.
        $manifest = $this->manifest();
        unset($this->bucket->bytes['metadata.json']);
        $outcome = $this->seal->seal($this->session(), $manifest, $this->participant);
        self::assertSame(SealOutcome::PENDING, $outcome->status, json_encode($outcome->toArray()));
        self::assertSame(['metadata.json'], $outcome->missing);

        $this->bucket->bytes['metadata.json'] = $this->sidecarBytes();
        $row = $this->manifests->findAccepted(self::RECORDING);
        self::assertNull($row);
        $pending = $this->manifests->findPending();
        self::assertCount(1, $pending);
        $reverified = $this->seal->reverify($pending[0]);
        self::assertSame(SealOutcome::VERIFIED, $reverified->status);
    }

    public function testAnArtefactTooLargeToVerifyIsPendingNotInvented(): void
    {
        $this->bucket->tooLarge['mesh.ply'] = 200_000_000;
        $manifest = $this->manifest();
        $manifest['artifacts'][] = ['name' => 'mesh.ply', 'sha256' => str_repeat('a', 64), 'bytes' => 200_000_000, 'content_type' => 'application/octet-stream'];
        $outcome = $this->seal->seal($this->session(), $manifest, $this->participant);
        self::assertSame(SealOutcome::PENDING, $outcome->status);
        self::assertContains('artifact_unverified:mesh.ply', $outcome->reasons);
    }

    // ── ownership ───────────────────────────────────────────────────────────────────────────

    public function testAStrangerCannotSealSomebodyElsesRecording(): void
    {
        $outcome = $this->seal->seal($this->session(), $this->manifest(), $this->stranger);
        self::assertSame(SealOutcome::INVALID, $outcome->status);
        self::assertContains('uploader: does not own this recording', $outcome->reasons);
    }

    public function testAManifestNamingAnotherEnrollmentIsInvalid(): void
    {
        [$otherEnrollment] = $this->enrol($this->participant);
        $manifest = $this->manifest();
        $manifest['enrollment_id'] = $otherEnrollment->getId()->toRfc4122();
        $outcome = $this->seal->seal($this->session(), $manifest, $this->participant);
        self::assertSame(SealOutcome::INVALID, $outcome->status);
        self::assertContains('enrollment_id: does not match the recording\'s enrollment', $outcome->reasons);
    }

    // ── the summary ─────────────────────────────────────────────────────────────────────────

    public function testASummaryUnderAnotherProtocolDigestFailsTheCompletion(): void
    {
        $summary = $this->summary();
        $summary['protocol_sha256'] = str_repeat('1', 64);
        $problems = $this->receiptService->recordCompletion($this->sweepStep, $summary);
        self::assertContains('protocol_sha256: does not match the frozen step snapshot', $problems);
    }

    public function testASummaryNamingAnotherStepFailsTheCompletion(): void
    {
        $summary = $this->summary();
        $summary['step_completion_id'] = '0c000000-0000-4000-8000-00000000000c';
        $problems = $this->receiptService->recordCompletion($this->sweepStep, $summary);
        self::assertContains('step_completion_id: does not name this completion', $problems);
    }

    public function testADifferentSummaryForTheSameSweepIsAConflict(): void
    {
        self::assertSame([], $this->receiptService->recordCompletion($this->sweepStep, $this->summary()));
        $this->em->flush();
        self::assertSame([], $this->receiptService->recordCompletion($this->sweepStep, $this->summary()), 'identical retry is silent');
        $changed = $this->summary();
        $changed['count'] = 9;
        $problems = $this->receiptService->recordCompletion($this->sweepStep, $changed);
        self::assertNotSame([], $problems);
        $this->em->flush();
        $receipt = $this->receipts->findForSweep(self::RECORDING, \Symfony\Component\Uid\Uuid::fromString(self::SWEEP));
        self::assertSame(LabReferenceReceipt::STATUS_CONFLICT, $receipt?->getStatus());
    }

    public function testASweepStepIsRecognisedFromItsFrozenSnapshotOnly(): void
    {
        self::assertTrue(SweepReceiptService::isSweepStep($this->sweepStep));
        $legacy = (new QuestStepCompletion())->setEnrollment($this->enrollment)->setStatus(QuestStepCompletionStatus::COMPLETED);
        $legacy->setStepSnapshot(['order' => 3, 'name' => 'Count', 'type' => 'observe', 'config' => ['prompt' => 'How many?', 'min_readings' => 5]]);
        self::assertFalse(SweepReceiptService::isSweepStep($legacy));
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────

    private function scrub(): void
    {
        $c = $this->em->getConnection();
        $c->executeStatement('DELETE FROM lab_reference_receipts WHERE recording_session_id LIKE :r', ['r' => '1a162000-%']);
        $c->executeStatement('DELETE FROM lab_evidence_manifests WHERE recording_session_id LIKE :r', ['r' => '1a162000-%']);
        $c->executeStatement('DELETE FROM lab_sessions WHERE id LIKE :r', ['r' => '1a162000-%']);
        $c->executeStatement(
            'DELETE FROM quest_step_completions WHERE enrollment_id IN '
            . '(SELECT e.id FROM quest_enrollments e JOIN quests q ON q.id = e.quest_id WHERE q.name LIKE :n)',
            ['n' => self::QUEST_PREFIX . '%'],
        );
        $c->executeStatement(
            'DELETE FROM quest_enrollments WHERE quest_id IN (SELECT id FROM quests WHERE name LIKE :n)',
            ['n' => self::QUEST_PREFIX . '%'],
        );
        $c->executeStatement('DELETE FROM quest_steps WHERE quest_id IN (SELECT id FROM quests WHERE name LIKE :n)', ['n' => self::QUEST_PREFIX . '%']);
        $c->executeStatement('DELETE FROM quests WHERE name LIKE :n', ['n' => self::QUEST_PREFIX . '%']);
        $c->executeStatement('DELETE FROM users WHERE email LIKE :e', ['e' => self::EMAIL_PREFIX . '%']);
    }

    private function makeUser(): User
    {
        $user = (new User())
            ->setEmail(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.test')
            ->setName('seal')
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function makeQuest(): Quest
    {
        $quest = (new Quest())
            ->setName(self::QUEST_PREFIX . bin2hex(random_bytes(3)))
            ->setDescription('A Counting quest this test owns.')
            ->setAvailableFrom(new \DateTime('2026-09-01T00:00:00+00:00'))
            ->setPoints(5.0)
            ->setCreatedBy($this->participant);
        $start = (new QuestStep())->setName('Before you start')->setType(QuestStepType::START)->setOrder(0)
            ->setConfig(['features' => ['broadcast' => true]]);
        $sweep = (new QuestStep())->setName('Count the room')->setType(QuestStepType::OBSERVE)->setOrder(1)
            ->setConfig($this->config);
        $quest->addStep($start);
        $quest->addStep($sweep);
        $this->em->persist($start);
        $this->em->persist($sweep);
        $this->em->persist($quest);
        $this->em->flush();

        return $quest;
    }

    /** @return array{0: QuestEnrollment, 1: QuestStepCompletion} the enrollment and its sweep step */
    private function enrol(User $user): array
    {
        $enrollment = (new QuestEnrollment())
            ->setUser($user)
            ->setQuest($this->quest)
            ->setStatus(QuestEnrollmentStatus::IN_PROGRESS);
        $this->em->persist($enrollment);
        $sweepCompletion = null;
        foreach ($this->quest->getSteps() as $index => $step) {
            $completion = (new QuestStepCompletion())
                ->setEnrollment($enrollment)
                ->setStep($step)
                ->setStatus(QuestStepCompletionStatus::IN_PROGRESS)
                ->snapshotStep($step, $index);
            $enrollment->addStepCompletion($completion);
            $this->em->persist($completion);
            if ($step->getType() === QuestStepType::OBSERVE) {
                $sweepCompletion = $completion;
            }
        }
        $this->em->flush();
        self::assertNotNull($sweepCompletion);

        return [$enrollment, $sweepCompletion];
    }

    private function registerRecording(): void
    {
        $this->bucket->bytes = [
            'markers.tsv' => "mono_ns\twall_ms\tkind\tstep_id\tlabel\tpayload_json\n100000000000\t1758441600000\theadcount\t"
                . $this->sweepStep->getId()->toRfc4122() . "\tstart\t{}\n",
            'clock.tsv' => "mono_ns\toffset_ns\trtt_ns\tskew_ppm\tsamples\n",
            'clock-exchanges.tsv' => "burst_id\tsource\tt1_ns\tt2_ns\tt3_ns\tt4_ns\tvalid\tkept\treason\n",
            'metadata.json' => $this->sidecarBytes(),
        ];
        $t0 = new \DateTimeImmutable('2026-09-22 10:00:00');
        foreach (['markers.tsv', 'clock.tsv', 'clock-exchanges.tsv'] as $name) {
            $this->register->artefactStored(self::RECORDING, $this->participant->getId()->toRfc4122(), $this->participant, $name, strlen($this->bucket->bytes[$name]), 'text/tab-separated-values', 'single', $t0);
        }
        $this->register->sessionCompleted(self::RECORDING, $this->participant->getId()->toRfc4122(), $this->participant, $this->sidecarBytes(), $t0->modify('+1 minute'));
        $this->em->clear();
        $this->enrollment = $this->em->find(QuestEnrollment::class, $this->enrollment->getId());
        $this->sweepStep = $this->em->find(QuestStepCompletion::class, $this->sweepStep->getId());
        $this->participant = $this->em->find(User::class, $this->participant->getId());
        $this->stranger = $this->em->find(User::class, $this->stranger->getId());
        $this->quest = $this->em->find(Quest::class, $this->quest->getId());
    }

    private function sidecarBytes(): string
    {
        return json_encode([
            'identity' => [
                'session_id' => self::RECORDING,
                'participant_id' => $this->participant->getId()->toRfc4122(),
                'enrollment_id' => $this->enrollment->getId()->toRfc4122(),
                'quest_id' => $this->quest->getId()->toRfc4122(),
                'site' => 'test-lab-synth-floor-0',
                'roles' => ['broadcaster', 'subject'],
            ],
            'environment' => ['platform' => 'ios', 'build_id' => 'fixture-build-1', 'clock_source' => 'http-reference', 'boot_id' => 'fixture-boot-1'],
            'lifecycle' => ['started_wall_ms' => 1_758_441_600_000, 'ended_wall_ms' => 1_758_441_760_000, 'ended_mono_ns' => 260_000_000_000, 'boot_id' => 'fixture-boot-1', 'monotonic_continuous' => true],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function session(): \App\Entity\LabSession
    {
        $session = $this->sessions->find(self::RECORDING);
        self::assertNotNull($session);

        return $session;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $artifacts = [];
        foreach ($this->bucket->bytes as $name => $bytes) {
            $artifacts[] = [
                'name' => $name,
                'sha256' => CanonicalJson::sha256Bytes($bytes),
                'bytes' => strlen($bytes),
                'content_type' => $name === 'metadata.json' ? 'application/json' : 'text/tab-separated-values',
            ];
        }

        return [
            'schema' => 'monad-lab/evidence-manifest/v1',
            'recording_session_id' => self::RECORDING,
            'enrollment_id' => $this->enrollment->getId()->toRfc4122(),
            'quest_id' => $this->quest->getId()->toRfc4122(),
            'step_completion_id' => $this->sweepStep->getId()->toRfc4122(),
            'sweep_ids' => [self::SWEEP],
            'app_build_id' => 'fixture-build-1',
            'payload_schemas' => ['monad-app/headcount-marker/v3'],
            'snapshot_sha256' => str_repeat('b', 64),
            'clock_domain' => ['boot_id' => 'fixture-boot-1', 'clock_source' => 'http-reference', 'monotonic_continuous' => true],
            'artifacts' => $artifacts,
            'sealed_mono_ns' => '260000000000',
            'sealed_wall_ms' => 1_758_441_760_000,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return [
            'schema' => 'monad-lab/sweep-summary/v1',
            'recording_session_id' => self::RECORDING,
            'sweep_id' => self::SWEEP,
            'step_completion_id' => $this->sweepStep->getId()->toRfc4122(),
            'protocol_id' => $this->config['protocol_id'],
            'protocol_sha256' => $this->config['protocol_sha256'],
            'room_id' => 'fixture-room-a',
            'coverage_version' => 'c1',
            'phase' => 'finalised',
            'final_event_id' => 'e0000000-0000-4000-8000-000000000005',
            'count' => 6,
            'coverage' => 'complete',
            'occupancy_stability' => 'stable',
            'events_accepted' => 5,
            'corrections' => 0,
        ];
    }
}

/** The staged bucket as a map; `tooLarge` names objects that exist but exceed the verification cap. */
final class FakeArtefactReader implements ArtefactReader
{
    /** @var array<string, string> */
    public array $bytes = [];
    /** @var array<string, int> */
    public array $tooLarge = [];

    public function read(string $participantId, string $recordingSessionId, string $filename, int $maxBytes): string|TooLargeToVerify|null
    {
        if (isset($this->tooLarge[$filename])) {
            return new TooLargeToVerify($this->tooLarge[$filename]);
        }

        return $this->bytes[$filename] ?? null;
    }
}
