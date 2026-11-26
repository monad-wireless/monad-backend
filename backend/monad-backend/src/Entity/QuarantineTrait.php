<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Taking a run out of the study without touching what it recorded (Version20261023120000).
 *
 * The columns and the reads, shared by QuestEnrollment and LabSession: a quarantine is an
 * operator's annotation BESIDE the measurement, never an edit of it. Time and reason move together
 * (a CHECK constraint enforces the pair), so a reader never meets a quarantine nobody explained.
 *
 * The WRITES differ on purpose. QuestEnrollment::quarantine()/release() go through the ORM;
 * LabSession is a read-only entity whose every write is SQL in LabSessionRepository, so its
 * quarantine is LabSessionRepository::quarantine()/release(). One rule, two write paths, each the
 * one its entity already uses.
 */
trait QuarantineTrait
{
    #[ORM\Column(name: 'quarantined_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $quarantinedAt = null;

    #[ORM\Column(name: 'quarantine_reason', type: Types::TEXT, nullable: true)]
    private ?string $quarantineReason = null;

    public function isQuarantined(): bool
    {
        return $this->quarantinedAt !== null;
    }

    public function getQuarantinedAt(): ?\DateTimeImmutable
    {
        return $this->quarantinedAt;
    }

    public function getQuarantineReason(): ?string
    {
        return $this->quarantineReason;
    }

    /** The one normalisation both write paths apply. @throws \InvalidArgumentException */
    public static function normaliseQuarantineReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A quarantine needs a reason.');
        }

        return $reason;
    }
}
