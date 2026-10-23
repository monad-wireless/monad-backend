<?php

namespace App\Tests\Quest;

use App\Quest\HandsetDescriptor;
use App\Quest\HandsetRegistry;
use App\Repository\HandsetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One row per installation, against a real PostgreSQL: the uniqueness IS a unique index.
 */
class HandsetRegistryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private HandsetRegistry $registry;
    private HandsetRepository $handsets;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->registry = $container->get(HandsetRegistry::class);
        $this->handsets = $container->get(HandsetRepository::class);
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

    /** @return array<string, mixed> */
    private static function descriptor(array $overrides = []): array
    {
        return $overrides + [
            'handset_id' => 'inst-0001',
            'platform' => 'android',
            'machine' => 'a54x',
            'manufacturer' => 'samsung',
            'model' => 'SM-A546B',
            'os_version' => '14',
            'app_version' => '1.4.0',
            'capabilities' => ['ble.advertise', 'ble.witness'],
        ];
    }

    public function testFirstObservationCreatesTheRow(): void
    {
        $handset = $this->registry->observe(HandsetDescriptor::fromArray(self::descriptor()));
        $this->em->flush();

        $found = $this->handsets->findByInstallationId('inst-0001');
        self::assertNotNull($found);
        self::assertSame($handset->getId()->toRfc4122(), $found->getId()->toRfc4122());
        self::assertSame('android', $found->getPlatform());
        self::assertSame('a54x', $found->getMachine());
        self::assertSame('samsung', $found->getManufacturer());
        self::assertSame(1, $found->getEnrollmentCount());
        self::assertSame(['ble.advertise', 'ble.witness'], $found->getCapabilities());
        self::assertSame('14', $found->getOsVersion());
    }

    public function testSecondObservationReusesTheRowAndRefreshesTheReadModel(): void
    {
        $first = $this->registry->observe(
            HandsetDescriptor::fromArray(self::descriptor()),
            new \DateTimeImmutable('2026-09-01 10:00:00'),
        );
        $this->em->flush();
        $this->em->clear();

        $second = $this->registry->observe(
            HandsetDescriptor::fromArray(self::descriptor(['os_version' => '15', 'capabilities' => ['ble.advertise']])),
            new \DateTimeImmutable('2026-09-04 09:00:00'),
        );
        $this->em->flush();
        $this->em->clear();

        self::assertSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122(), 'same installation, same row');
        self::assertSame(1, count($this->handsets->findAll()));

        $row = $this->handsets->findByInstallationId('inst-0001');
        self::assertNotNull($row);
        self::assertSame(2, $row->getEnrollmentCount());
        self::assertSame('15', $row->getOsVersion(), 'last_descriptor is the latest');
        self::assertSame(['ble.advertise'], $row->getCapabilities());
        self::assertSame('2026-09-01 10:00:00', $row->getFirstSeenAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-04 09:00:00', $row->getLastSeenAt()->format('Y-m-d H:i:s'));
    }

    public function testABuildThatStopsReportingAStableFactDoesNotBlankIt(): void
    {
        $this->registry->observe(HandsetDescriptor::fromArray(self::descriptor()));
        $this->em->flush();
        $this->em->clear();

        $this->registry->observe(HandsetDescriptor::fromArray(['handset_id' => 'inst-0001', 'platform' => 'android']));
        $this->em->flush();
        $this->em->clear();

        $row = $this->handsets->findByInstallationId('inst-0001');
        self::assertNotNull($row);
        self::assertSame('a54x', $row->getMachine());
        self::assertSame('samsung', $row->getManufacturer());
    }

    public function testTwoInstallationsOnOneMachineAreTwoRows(): void
    {
        // A reinstall is a new handset by decision (IP-149 Q1); the inventory groups by machine.
        $this->registry->observe(HandsetDescriptor::fromArray(self::descriptor(['handset_id' => 'inst-0001'])));
        $this->registry->observe(HandsetDescriptor::fromArray(self::descriptor(['handset_id' => 'inst-0002'])));
        $this->em->flush();

        $byMachine = $this->handsets->countByMachine();
        self::assertCount(1, $byMachine);
        self::assertSame('a54x', $byMachine[0]['machine']);
        self::assertSame(2, $byMachine[0]['installations']);
        self::assertSame(2, $byMachine[0]['enrollments']);
    }
}
