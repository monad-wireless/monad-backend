<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quarantine for runs: `quarantined_at` + `quarantine_reason` on enrollments and recording sessions.
 *
 * Until now the only way to take a run out of the study was the enrollment Delete button, which
 * cascaded into its step completions and left the recording session, its ground-truth scans and its
 * S3 artefacts behind with nothing marking them. A quarantine deletes nothing: the row stays as it
 * was recorded, and every reader decides from the two columns. The first use is the App Store
 * reviewer's demo account (2026-10-23), whose runs must never enter an analysis.
 *
 * Set from the admin reading pages; read by `lab_runs`, which hides a recording when it or its
 * enrollment is quarantined.
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'quest_enrollments + lab_sessions: quarantined_at, quarantine_reason (never delete a run)';
    }

    public function up(Schema $schema): void
    {
        foreach (['quest_enrollments', 'lab_sessions'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD quarantined_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD quarantine_reason TEXT DEFAULT NULL', $table));
            $this->addSql(sprintf(
                "COMMENT ON COLUMN %s.quarantined_at IS '(DC2Type:datetime_immutable) When an operator took this "
                ."run out of the study. NULL means it is in. The row itself is never edited or deleted.'",
                $table,
            ));
            $this->addSql(sprintf(
                "COMMENT ON COLUMN %s.quarantine_reason IS 'Why, in the operator''s words. Set together with "
                ."quarantined_at and cleared together with it.'",
                $table,
            ));
            // Both columns move together; a half-set pair is a bug the database should refuse.
            $this->addSql(sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT %1$s_quarantine_pair CHECK '
                .'((quarantined_at IS NULL) = (quarantine_reason IS NULL))',
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['quest_enrollments', 'lab_sessions'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %1$s DROP CONSTRAINT %1$s_quarantine_pair', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP quarantine_reason', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP quarantined_at', $table));
        }
    }
}
