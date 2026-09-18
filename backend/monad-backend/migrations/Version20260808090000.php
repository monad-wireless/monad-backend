<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The ground-truth (people) channel: per-participant zone check-in/out, and its contradictions.
 *
 * `ground_truth_scans` carries the pre-registered `ground_truth.tsv` columns verbatim
 * (`lab-session-2026-08-prereg-v2.md` §3.5) so the live tally and the archived artefact are the
 * same nine fields under the same nine names.
 *
 * The unique index on `scan_nonce` is the load-bearing part. Ten to twelve handsets each re-upload
 * their complete set on every flush, so the same nonce arrives repeatedly and concurrently; the
 * database is what makes that safe, not application-level checking, which would race.
 *
 * `ground_truth_conflicts` exists because the pre-registration forbids overwriting: a nonce
 * claimed twice with a different `(participant_token, zone_id, direction)` is exclusion E3, and
 * "contradictions are logged, never reconciled by judgement". The refused claim has to survive
 * somewhere, or the evidence that anything was ever wrong is gone.
 *
 * Both tables are pseudonymous by construction — `participant_token` only, no foreign key to
 * `users`, so a join from a scan to an account is not expressible in the schema.
 */
final class Version20260808090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ground_truth_scans and ground_truth_conflicts for the lab people-count channel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ground_truth_scans (
                id UUID NOT NULL,
                lab_session_id VARCHAR(128) NOT NULL,
                participant_token VARCHAR(128) NOT NULL,
                zone_id VARCHAR(128) NOT NULL,
                direction VARCHAR(8) NOT NULL,
                site VARCHAR(128) NOT NULL,
                mono_ns BIGINT NOT NULL,
                wall_ms BIGINT NOT NULL,
                scan_nonce VARCHAR(128) NOT NULL,
                recording_session_id VARCHAR(128) DEFAULT NULL,
                received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX ground_truth_scan_nonce_idx ON ground_truth_scans (scan_nonce)');
        $this->addSql('CREATE INDEX ground_truth_session_zone_idx ON ground_truth_scans (lab_session_id, zone_id)');
        $this->addSql("COMMENT ON COLUMN ground_truth_scans.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN ground_truth_scans.received_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE ground_truth_conflicts (
                id UUID NOT NULL,
                lab_session_id VARCHAR(128) NOT NULL,
                scan_nonce VARCHAR(128) NOT NULL,
                zone_id VARCHAR(128) NOT NULL,
                accepted_triple VARCHAR(384) NOT NULL,
                rejected_triple VARCHAR(384) NOT NULL,
                rejected_mono_ns BIGINT NOT NULL,
                rejected_wall_ms BIGINT NOT NULL,
                observed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        // Deliberately NOT unique on scan_nonce: one contested nonce can be claimed by three
        // phones, and each disagreement is its own evidence about the interval E3 excludes.
        $this->addSql('CREATE INDEX ground_truth_conflict_session_idx ON ground_truth_conflicts (lab_session_id)');
        $this->addSql("COMMENT ON COLUMN ground_truth_conflicts.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN ground_truth_conflicts.observed_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ground_truth_conflicts');
        $this->addSql('DROP TABLE ground_truth_scans');
    }
}
