<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * IP-162: the evidence seal and the reference receipts.
 *
 * Two tables, both additive, on the rule Version20260901150000 set: `docker/entrypoint.sh`
 * migrates on container boot, so every migration is a chance to take the API down and nothing
 * here alters an existing column.
 *
 * 1. `lab_evidence_manifests` — every attempt to seal a recording's uploaded bytes under
 *    `monad-lab/evidence-manifest/v1`, with its disposition (accepted | pending | invalid |
 *    conflict). `(recording_session_id, manifest_sha256)` is unique so an identical retry is one
 *    row; the partial unique index on `recording_session_id WHERE state = 'accepted'` is the rule
 *    the proposal states in words: one accepted original seal per recording, and a different
 *    digest is not permission to reseal. Rejected and conflicting attempts are kept as rows with
 *    their disposition, never as additional accepted seals.
 *
 * 2. `lab_reference_receipts` — one row per `(recording, sweep)`: the sweep summary a quest
 *    completion carried in `step_data`, and how far reconciliation against the seal got
 *    (pending | verified | invalid | conflict). Completion-first and upload-first arrivals both
 *    land as `pending` and reconcile to the same result.
 *
 * `recording_session_id` is the sanitised app session id, the same string `lab_sessions.id`
 * holds, and is NOT a foreign key on purpose: a seal or a summary may arrive before the register
 * row exists (the register is written by the upload path, the summary by the completion path),
 * and refusing the earlier arrival would make the order of two independent uploads a correctness
 * condition. The join is by value, as it is between `ground_truth_scans` and `lab_sessions`.
 * `enrollment_id` and `step_completion_id` on the receipts follow the same rule: an evidence
 * pointer records what the phone claimed, and what later happens to the row it names must
 * neither null it nor block it (the register tests truncate `quest_step_completions`).
 * `uploaded_by` on the manifests is a real foreign key, as every other pointer to `users` is.
 *
 * Timestamps are TIMESTAMPTZ, as every table since IP-157.
 */
final class Version20260922090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'IP-162: lab_evidence_manifests and lab_reference_receipts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE lab_evidence_manifests (
              id                    UUID PRIMARY KEY,
              recording_session_id  VARCHAR(128) NOT NULL,
              manifest_sha256       VARCHAR(64) NOT NULL,
              state                 VARCHAR(16) NOT NULL,
              manifest              JSONB NOT NULL,
              verification          JSONB NOT NULL DEFAULT '{}'::jsonb,
              reasons               JSONB NOT NULL DEFAULT '[]'::jsonb,
              uploaded_by           UUID REFERENCES users (id) ON DELETE SET NULL,
              received_at           TIMESTAMPTZ NOT NULL,
              verified_at           TIMESTAMPTZ
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX lab_evidence_manifest_identity ON lab_evidence_manifests (recording_session_id, manifest_sha256)');
        $this->addSql('CREATE INDEX lab_evidence_manifest_session_idx ON lab_evidence_manifests (recording_session_id)');
        // One accepted seal per recording. A second accepted row for the same recording is a
        // constraint violation, whatever code path tried to write it.
        $this->addSql("CREATE UNIQUE INDEX lab_evidence_manifest_one_accepted ON lab_evidence_manifests (recording_session_id) WHERE state = 'accepted'");

        $this->addSql(<<<'SQL'
            CREATE TABLE lab_reference_receipts (
              id                    UUID PRIMARY KEY,
              recording_session_id  VARCHAR(128) NOT NULL,
              sweep_id              UUID NOT NULL,
              enrollment_id         UUID,
              step_completion_id    UUID,
              protocol_sha256       VARCHAR(64) NOT NULL,
              final_event_id        UUID,
              manifest_sha256       VARCHAR(64),
              summary               JSONB NOT NULL,
              status                VARCHAR(16) NOT NULL,
              reasons               JSONB NOT NULL DEFAULT '[]'::jsonb,
              received_at           TIMESTAMPTZ NOT NULL,
              reconciled_at         TIMESTAMPTZ
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX lab_reference_receipt_sweep ON lab_reference_receipts (recording_session_id, sweep_id)');
        $this->addSql('CREATE INDEX lab_reference_receipt_session_idx ON lab_reference_receipts (recording_session_id)');
        $this->addSql('CREATE INDEX lab_reference_receipt_status_idx ON lab_reference_receipts (status)');
    }

    public function down(Schema $schema): void
    {
        // Reversible while no v3 evidence exists. Once a manifest has been accepted these rows
        // ARE the seal record, and dropping them would silently un-seal every recording the app
        // has already purged from the phone. Refuse when any accepted seal exists.
        $this->abortIf(
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM lab_evidence_manifests WHERE state = 'accepted'") > 0,
            'Irreversible: accepted evidence seals exist and the phones that recorded them have purged their copies.',
        );
        $this->addSql('DROP TABLE lab_reference_receipts');
        $this->addSql('DROP TABLE lab_evidence_manifests');
    }
}
