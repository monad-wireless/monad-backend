<?php

namespace App\Tests\Service;

use App\Entity\LabSession;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Quest\HandsetDescriptor;
use App\Quest\HandsetRegistry;
use App\Repository\LabSessionRepository;
use App\Service\LabSessionRegister;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The register against a real PostgreSQL (IP-149).
 *
 * The properties under test — artefacts accumulating in any order, a completion
 * instant that is set once, foreign keys resolved rather than trusted — live in
 * the upsert statements, and a double cannot exercise `ON CONFLICT`.
 */
class LabSessionRegisterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LabSessionRegister $register;
    private LabSessionRepository $sessions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->register = $container->get(LabSessionRegister::class);
        $this->sessions = $container->get(LabSessionRepository::class);
        // push_tokens is in the list because it references handsets (IP-157) and PostgreSQL
        // refuses to truncate a referenced table on its own.
        $this->em->getConnection()->executeStatement(
            'TRUNCATE lab_sessions, quest_step_skip_records, quest_step_completions, quest_enrollments, push_tokens, handsets'
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }

    private function load(string $id): LabSession
    {
        $this->em->clear();
        $row = $this->sessions->find($id);
        self::assertInstanceOf(LabSession::class, $row);

        return $row;
    }

    /** @return array<string, mixed> */
    private static function sidecar(array $overrides = []): array
    {
        return array_replace_recursive([
            'identity' => [
                'session_id' => 'sess-1',
                'participant_id' => 'p-1',
                'enrollment_id' => '11111111-1111-4111-8111-111111111111',
                'quest_id' => '22222222-2222-4222-8222-222222222222',
                'site' => 'fiit-ground-0',
                'roles' => ['broadcaster', 'tracker'],
            ],
            'environment' => [
                'platform' => 'ios',
                'os_version' => '18.6',
                'device_model' => 'iPhone15,2',
                'build_id' => '1.4.0+41.g9a1d2f2b',
                'handset' => ['handset_id' => 'inst-ios-1', 'platform' => 'ios', 'machine' => 'iPhone15,2'],
            ],
            'lifecycle' => ['started_wall_ms' => 1_757_000_000_000, 'ended_wall_ms' => 1_757_000_600_000],
            'summary' => ['pose_samples' => 4200],
        ], $overrides);
    }

    public function testArtefactsAccumulateInAnyOrder(): void
    {
        $t0 = new \DateTimeImmutable('2026-09-04 10:00:00');
        $this->register->artefactStored('sess-1', 'p-1', null, 'pose.tsv', 120_000, 'text/tab-separated-values', 'single', $t0);
        $this->register->artefactStored('sess-1', 'p-1', null, 'mesh.ply', 102_000_000, 'application/octet-stream', 'multipart', $t0->modify('+1 minute'));
        $this->register->artefactStored('sess-1', 'p-1', null, 'markers.tsv', 800, 'text/tab-separated-values', 'single', $t0->modify('+2 minutes'));

        $row = $this->load('sess-1');
        self::assertSame(3, $row->getArtefactCount());
        self::assertSame(102_120_800, $row->getArtefactBytes());
        self::assertSame('multipart', $row->getArtefacts()['mesh.ply']['transport']);
        self::assertSame('2026-09-04 10:00:00', $row->getFirstArtefactAt()->format('Y-m-d H:i:s'), 'first sight is kept');
        self::assertFalse($row->isComplete());
    }

    public function testReplayedArtefactDoesNotDuplicate(): void
    {
        $this->register->artefactStored('sess-1', 'p-1', null, 'pose.tsv', 100, 'text/tab-separated-values', 'single');
        $this->register->artefactStored('sess-1', 'p-1', null, 'pose.tsv', 100, 'text/tab-separated-values', 'single');

        self::assertSame(1, $this->load('sess-1')->getArtefactCount());
    }

    public function testSidecarCompletesTheRowAndResolvesNothingItCannotFind(): void
    {
        $this->register->artefactStored('sess-1', 'p-1', null, 'pose.tsv', 100, 'text/tab-separated-values', 'single');
        $ok = $this->register->sessionCompleted(
            'sess-1',
            'p-1',
            null,
            json_encode(self::sidecar(), JSON_THROW_ON_ERROR),
            new \DateTimeImmutable('2026-09-04 10:05:00'),
        );
        self::assertTrue($ok);

        $row = $this->load('sess-1');
        self::assertTrue($row->isComplete());
        self::assertSame('2026-09-04 10:05:00', $row->getCompletedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('fiit-ground-0', $row->getSite());
        self::assertSame(['broadcaster', 'tracker'], $row->getRoles());
        self::assertSame('ios', $row->getPlatform());
        self::assertSame('iPhone15,2', $row->getMachine());
        self::assertSame('1.4.0+41.g9a1d2f2b', $row->getBuildId());
        self::assertSame(600, $row->getDurationSeconds());
        self::assertSame(4200, $row->getSidecar()['summary']['pose_samples'] ?? null, 'the whole sidecar is kept');
        // The sidecar names an enrollment, a quest and a handset this database has never seen.
        self::assertNull($row->getEnrollment());
        self::assertNull($row->getQuest());
        self::assertNull($row->getHandset());
        self::assertSame(1, $row->getArtefactCount(), 'completion does not touch the artefact map');
    }

    public function testCompletionInstantIsSetOnce(): void
    {
        $raw = json_encode(self::sidecar(), JSON_THROW_ON_ERROR);
        $this->register->sessionCompleted('sess-1', 'p-1', null, $raw, new \DateTimeImmutable('2026-09-04 10:05:00'));
        // The client re-uploads its whole set on every flush.
        $this->register->sessionCompleted('sess-1', 'p-1', null, $raw, new \DateTimeImmutable('2026-09-04 11:00:00'));

        self::assertSame('2026-09-04 10:05:00', $this->load('sess-1')->getCompletedAt()?->format('Y-m-d H:i:s'));
    }

    public function testSidecarBeforeAnyArtefactStillCreatesTheRow(): void
    {
        // Order is a client contract, not a server assumption.
        $this->register->sessionCompleted('sess-1', 'p-1', null, json_encode(self::sidecar(), JSON_THROW_ON_ERROR));
        $this->register->artefactStored('sess-1', 'p-1', null, 'pose.tsv', 100, 'text/tab-separated-values', 'single');

        $row = $this->load('sess-1');
        self::assertTrue($row->isComplete());
        self::assertSame(1, $row->getArtefactCount());
    }

    public function testNonJsonSidecarWritesNothing(): void
    {
        self::assertFalse($this->register->sessionCompleted('sess-1', 'p-1', null, 'not json'));
        $this->em->clear();
        self::assertNull($this->sessions->find('sess-1'));
    }

    public function testHandsetIsResolvedThroughTheRegistry(): void
    {
        /** @var HandsetRegistry $registry */
        $registry = static::getContainer()->get(HandsetRegistry::class);
        $handset = $registry->observe(HandsetDescriptor::fromArray(['handset_id' => 'inst-ios-1', 'platform' => 'ios', 'machine' => 'iPhone15,2']));
        $this->em->flush();

        $this->register->sessionCompleted('sess-1', 'p-1', null, json_encode(self::sidecar(), JSON_THROW_ON_ERROR));

        $row = $this->load('sess-1');
        self::assertSame($handset->getId()->toRfc4122(), $row->getHandset()?->getId()->toRfc4122());
    }

    public function testParticipantIdThatIsAUserIdResolvesTheUser(): void
    {
        $user = (new User())
            ->setEmail(sprintf('register-%s@example.test', uniqid()))
            ->setPassword('x')
            ->setStatus(UserStatus::ACTIVE);
        $this->em->persist($user);
        $this->em->flush();
        $userId = $user->getId()?->toRfc4122();
        self::assertNotNull($userId);

        // A backfilled row has no authenticated uploader; the participant key stands in.
        $this->register->sessionCompleted('sess-1', $userId, null, json_encode(self::sidecar(), JSON_THROW_ON_ERROR));

        self::assertSame($userId, $this->load('sess-1')->getUser()?->getId()?->toRfc4122());

        $this->em->getConnection()->executeStatement('DELETE FROM lab_sessions WHERE id = :id', ['id' => 'sess-1']);
        $this->em->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $userId]);
    }

    public function testSessionIdIsSanitisedLikeTheS3Key(): void
    {
        $this->register->artefactStored('sess/../1', 'p 1', null, '../pose.tsv', 100, 'text/tab-separated-values', 'single');

        $row = $this->load('sess_.._1');
        self::assertSame('p_1', $row->getParticipantId());
        self::assertTrue($row->hasArtefact('pose.tsv'));
    }

    public function testActivitySince(): void
    {
        $old = new \DateTimeImmutable('2026-08-01 10:00:00');
        $this->register->artefactStored('old', 'p-1', null, 'pose.tsv', 1_000, 'text/tab-separated-values', 'single', $old);
        $this->register->sessionCompleted('old', 'p-1', null, json_encode(self::sidecar(), JSON_THROW_ON_ERROR), $old);
        $this->register->artefactStored('new', 'p-1', null, 'pose.tsv', 2_000, 'text/tab-separated-values', 'single', new \DateTimeImmutable('2026-09-04 10:00:00'));

        $all = $this->sessions->activitySince(null);
        self::assertSame(['sessions' => 2, 'bytes' => 3_000, 'incomplete' => 1], $all);

        $recent = $this->sessions->activitySince(new \DateTimeImmutable('2026-09-01 00:00:00'));
        self::assertSame(['sessions' => 1, 'bytes' => 2_000, 'incomplete' => 1], $recent);
    }
}
