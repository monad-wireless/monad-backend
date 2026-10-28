<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Devices as first-class rows, and the columns that make a quest replayable per node (IP-128).
 *
 * A QR sticker on a node encodes `https://monad.dubec.dev/d/<slug>`. Until now the
 * backend had no idea what a node was: hardware appeared only as free-form keys
 * inside `quest_steps.config` (`ap_id`, `site_ref`, `profile_id`) and as
 * `access_points[].host_node` in the operator-authored `config/lab/lab.json`.
 * Nothing could answer "which quests does monad04 arm" or "has this person
 * already run this here", which is the whole premise of the sticker.
 *
 * EVERY CHANGE IS ADDITIVE AND DEFAULTED. `docker/entrypoint.sh` migrates on
 * container boot, so a failing up() takes the API down rather than failing a
 * deploy step; and every existing row and every deployed client must keep the
 * behaviour it has. In particular `quests.recurrence` defaults to NULL, which
 * means UNLIMITED, not once — nothing in this backend has ever prevented a
 * replay (Version20251202185420 dropped the unique enrollment index), so a
 * default of "once" would be a silent behaviour change shipped as a schema tweak.
 *
 * `quest_enrollments.completion_received_at` exists because
 * `QuestController::completeQuest()` sets `completed_at` from the request body.
 * A cooldown measured against a client-supplied timestamp is not a cooldown —
 * a backdated finish clears it instantly. The server stamps this one on receipt
 * and the recurrence gate reads only this.
 *
 * `quest_enrollments.device_id` is measurement provenance once written, not
 * bookkeeping: it records which physical node produced a run. ON DELETE RESTRICT
 * is deliberate — a device row may be deactivated, never deleted, because
 * deleting it would silently rewrite what past runs mean.
 */
final class Version20260813170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'IP-128: devices table, quest arming + recurrence, per-enrollment device and server-side completion stamp';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE devices (
                id UUID NOT NULL,
                slug VARCHAR(32) NOT NULL,
                label VARCHAR(255) NOT NULL,
                location TEXT DEFAULT NULL,
                site_ref VARCHAR(255) DEFAULT NULL,
                public_blurb TEXT DEFAULT NULL,
                radio_mac VARCHAR(17) DEFAULT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                commissioned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_devices_slug ON devices (slug)');
        $this->addSql('CREATE INDEX device_active_idx ON devices (is_active)');
        $this->addSql("COMMENT ON COLUMN devices.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN devices.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN devices.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN devices.commissioned_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN devices.radio_mac IS 'Operator-only. Never serialised into a public response.'");

        // Which nodes a quest is offered at. Empty set = every node, which is what
        // every quest written before IP-128 means.
        $this->addSql(<<<'SQL'
            CREATE TABLE quest_devices (
                quest_id UUID NOT NULL,
                device_id UUID NOT NULL,
                PRIMARY KEY(quest_id, device_id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_quest_devices_quest ON quest_devices (quest_id)');
        $this->addSql('CREATE INDEX idx_quest_devices_device ON quest_devices (device_id)');
        $this->addSql("COMMENT ON COLUMN quest_devices.quest_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN quest_devices.device_id IS '(DC2Type:uuid)'");
        $this->addSql(<<<'SQL'
            ALTER TABLE quest_devices
                ADD CONSTRAINT fk_quest_devices_quest FOREIGN KEY (quest_id)
                REFERENCES quests (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE quest_devices
                ADD CONSTRAINT fk_quest_devices_device FOREIGN KEY (device_id)
                REFERENCES devices (id) ON DELETE CASCADE
        SQL);

        // NULL = unlimited (the status quo), not once. See the class docblock.
        $this->addSql('ALTER TABLE quests ADD recurrence JSON DEFAULT NULL');
        $this->addSql(
            "COMMENT ON COLUMN quests.recurrence IS "
            ."'{scope: per_device|per_quest, cooldown_seconds: int}. NULL means unlimited replays.'"
        );

        $this->addSql('ALTER TABLE quest_enrollments ADD device_id UUID DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN quest_enrollments.device_id IS '(DC2Type:uuid)'");
        $this->addSql(<<<'SQL'
            ALTER TABLE quest_enrollments
                ADD CONSTRAINT fk_quest_enrollments_device FOREIGN KEY (device_id)
                REFERENCES devices (id) ON DELETE RESTRICT
        SQL);
        $this->addSql('CREATE INDEX user_quest_device_idx ON quest_enrollments (user_id, quest_id, device_id)');

        // Server-authoritative finish time. The recurrence gate reads ONLY this.
        $this->addSql('ALTER TABLE quest_enrollments ADD completion_received_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Monotonic clock per step, so quest labels can be time-joined to CSI.
        // Nullable: existing rows and older clients stay valid. BIGINT because
        // nanoseconds overflow 32-bit, and it maps to a PHP string for the same reason.
        $this->addSql('ALTER TABLE quest_step_completions ADD mono_ns BIGINT DEFAULT NULL');
        $this->addSql(
            "COMMENT ON COLUMN quest_step_completions.mono_ns IS "
            ."'Monotonic nanoseconds from the handset. Pairs with completed_at; see GroundTruthScan.mono_ns.'"
        );
        $this->addSql(
            "COMMENT ON COLUMN quest_enrollments.completion_received_at IS "
            ."'(DC2Type:datetime_immutable) Stamped by the server on receipt. completed_at is client-reported.'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_enrollments DROP CONSTRAINT fk_quest_enrollments_device');
        $this->addSql('DROP INDEX user_quest_device_idx');
        $this->addSql('ALTER TABLE quest_enrollments DROP device_id');
        $this->addSql('ALTER TABLE quest_enrollments DROP completion_received_at');
        $this->addSql('ALTER TABLE quest_step_completions DROP mono_ns');
        $this->addSql('ALTER TABLE quests DROP recurrence');
        $this->addSql('ALTER TABLE quest_devices DROP CONSTRAINT fk_quest_devices_quest');
        $this->addSql('ALTER TABLE quest_devices DROP CONSTRAINT fk_quest_devices_device');
        $this->addSql('DROP TABLE quest_devices');
        $this->addSql('DROP TABLE devices');
    }
}
