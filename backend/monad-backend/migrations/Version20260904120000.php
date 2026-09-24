<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The handset behind the run, and the session register (IP-149).
 *
 * One migration for two tables and two columns, on the rule Version20260901150000
 * set: `docker/entrypoint.sh` migrates on container boot, so every separate
 * migration is a separate chance to take the API down. EVERY CHANGE IS ADDITIVE
 * AND NULLABLE OR DEFAULTED — no existing row changes meaning and no deployed
 * client changes behaviour.
 *
 * 1. `handsets`
 * -------------
 * One row per app installation, keyed by the UUID the app generates once and
 * keeps in its settings. NOT a fleet `Device`: a device is the box that listened,
 * a handset is the phone that walked. A reinstall is a new row by decision; no
 * platform device identifier is stored. `last_descriptor` is a read model for the
 * inventory page — the evidence is the per-run snapshot below.
 *
 * 2. `quest_enrollments.handset_id` / `handset_snapshot`
 * ------------------------------------------------------
 * Written once in `QuestController::startQuest()` from the request body, never
 * updated, never edited. Same posture as `device_id` (IP-128): measurement
 * provenance, and an admin screen that could move a run to another phone would
 * make the column unenforceable, invisibly. NULL on both means an app build that
 * predates this migration sent no body, and that stays valid.
 *
 * `handset_snapshot` is the descriptor VERBATIM. Not normalised, because the
 * question the analysis asks is "what did this phone report at that moment", and
 * a normalisation step is a place where two builds can be made to look alike.
 *
 * 3. `lab_sessions`
 * -----------------
 * The register of recording sessions the app uploads. Until now the sidecar
 * (`metadata.json`) was parsed for four telemetry histograms and discarded, and
 * the S3 prefix was the only list of what had been recorded. A session whose
 * upload failed half way was indistinguishable from one nobody ran.
 *
 * Written by native upserts (`INSERT … ON CONFLICT DO UPDATE SET artefacts =
 * artefacts || excluded.artefacts`), because ten handsets flush concurrently and
 * two artefacts of one session may land in the same millisecond. The entity is
 * read-only. `id` is the app's session id after the S3 key sanitiser, so the row
 * id equals the S3 prefix segment and the two registers join by string equality.
 *
 * NOT the ground-truth session. `ground_truth_scans.lab_session_id` is the event
 * the phones stamp on scans; THIS is one phone's uploaded artefacts. A scan joins
 * a recording session through `ground_truth_scans.recording_session_id`, and
 * nothing else joins them.
 */
final class Version20260904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'IP-149: handsets, per-enrollment handset provenance, and the recording-session register';
    }

    public function up(Schema $schema): void
    {
        // ── 1. handsets ──────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE handsets (
                id UUID NOT NULL,
                installation_id VARCHAR(64) NOT NULL,
                platform VARCHAR(16) NOT NULL,
                machine VARCHAR(64) DEFAULT NULL,
                manufacturer VARCHAR(64) DEFAULT NULL,
                model VARCHAR(128) DEFAULT NULL,
                label VARCHAR(255) DEFAULT NULL,
                last_descriptor JSONB NOT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                enrollment_count INT DEFAULT 0 NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_handsets_installation ON handsets (installation_id)');
        $this->addSql('CREATE INDEX handset_platform_machine_idx ON handsets (platform, machine)');
        $this->addSql('CREATE INDEX handset_last_seen_idx ON handsets (last_seen_at)');
        $this->addSql("COMMENT ON COLUMN handsets.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN handsets.first_seen_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN handsets.last_seen_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql(
            "COMMENT ON TABLE handsets IS "
            ."'One app installation on one phone (IP-149). NOT a fleet device. A reinstall is a new row; "
            ."no platform device identifier is stored. last_descriptor is a read model; the evidence "
            ."is quest_enrollments.handset_snapshot.'"
        );

        // ── 2. enrollment provenance ─────────────────────────────────────────
        $this->addSql('ALTER TABLE quest_enrollments ADD handset_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_enrollments ADD handset_snapshot JSONB DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN quest_enrollments.handset_id IS '(DC2Type:uuid)'");
        $this->addSql(
            "COMMENT ON COLUMN quest_enrollments.handset_snapshot IS "
            ."'The handset descriptor as sent at start, verbatim. Written once, never updated. "
            ."NULL means the app build sent none (pre-IP-149).'"
        );
        $this->addSql(
            'ALTER TABLE quest_enrollments ADD CONSTRAINT FK_enrollment_handset '
            .'FOREIGN KEY (handset_id) REFERENCES handsets (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql('CREATE INDEX enrollment_handset_idx ON quest_enrollments (handset_id)');

        // ── 3. the recording-session register ────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE lab_sessions (
                id VARCHAR(128) NOT NULL,
                participant_id VARCHAR(128) NOT NULL,
                user_id UUID DEFAULT NULL,
                enrollment_id UUID DEFAULT NULL,
                quest_id UUID DEFAULT NULL,
                handset_id UUID DEFAULT NULL,
                site VARCHAR(128) DEFAULT NULL,
                roles JSONB NOT NULL,
                platform VARCHAR(16) DEFAULT NULL,
                machine VARCHAR(64) DEFAULT NULL,
                build_id VARCHAR(128) DEFAULT NULL,
                started_wall_ms BIGINT DEFAULT NULL,
                ended_wall_ms BIGINT DEFAULT NULL,
                interrupted_reason TEXT DEFAULT NULL,
                sidecar JSONB DEFAULT NULL,
                artefacts JSONB NOT NULL,
                first_artefact_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX lab_session_started_idx ON lab_sessions (started_wall_ms)');
        $this->addSql('CREATE INDEX lab_session_participant_idx ON lab_sessions (participant_id)');
        $this->addSql('CREATE INDEX lab_session_enrollment_idx ON lab_sessions (enrollment_id)');
        $this->addSql('CREATE INDEX lab_session_completed_idx ON lab_sessions (completed_at)');
        $this->addSql('CREATE INDEX IDX_lab_sessions_user ON lab_sessions (user_id)');
        $this->addSql('CREATE INDEX IDX_lab_sessions_quest ON lab_sessions (quest_id)');
        $this->addSql('CREATE INDEX IDX_lab_sessions_handset ON lab_sessions (handset_id)');
        $this->addSql("COMMENT ON COLUMN lab_sessions.user_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN lab_sessions.enrollment_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN lab_sessions.quest_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN lab_sessions.handset_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN lab_sessions.first_artefact_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN lab_sessions.completed_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN lab_sessions.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql(
            "COMMENT ON TABLE lab_sessions IS "
            ."'One recording session the app uploaded (IP-149): the register behind "
            ."datasets/monad-app-sessions/. Written by the upload path through native upserts. "
            ."completed_at is when metadata.json arrived; NULL means streams landed, no sidecar. "
            ."NOT the ground-truth session (ground_truth_scans.lab_session_id).'"
        );
        // SET NULL rather than RESTRICT on these three: the register is derived from what the
        // phone uploaded and can be rebuilt from S3 by app:lab-sessions:backfill, so it must not
        // stand in the way of anonymising a user or cleaning a quest.
        $this->addSql(
            'ALTER TABLE lab_sessions ADD CONSTRAINT FK_lab_session_user '
            .'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql(
            'ALTER TABLE lab_sessions ADD CONSTRAINT FK_lab_session_enrollment '
            .'FOREIGN KEY (enrollment_id) REFERENCES quest_enrollments (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql(
            'ALTER TABLE lab_sessions ADD CONSTRAINT FK_lab_session_quest '
            .'FOREIGN KEY (quest_id) REFERENCES quests (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql(
            'ALTER TABLE lab_sessions ADD CONSTRAINT FK_lab_session_handset '
            .'FOREIGN KEY (handset_id) REFERENCES handsets (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lab_sessions');
        $this->addSql('ALTER TABLE quest_enrollments DROP CONSTRAINT FK_enrollment_handset');
        $this->addSql('DROP INDEX enrollment_handset_idx');
        $this->addSql('ALTER TABLE quest_enrollments DROP handset_snapshot');
        $this->addSql('ALTER TABLE quest_enrollments DROP handset_id');
        $this->addSql('DROP TABLE handsets');
    }
}
