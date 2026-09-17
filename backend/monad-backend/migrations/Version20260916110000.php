<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The IP-157 foundation: placement mirror, notifications, push tokens, beta signups, and the
 * three per-user notification columns.
 *
 * One migration for five tables and three columns, on the rule Version20260901150000 set:
 * `docker/entrypoint.sh` migrates on container boot, so every separate migration is a separate
 * chance to take the API down. EVERY CHANGE IS ADDITIVE AND NULLABLE OR DEFAULTED.
 *
 * 1. `lab_placements` — a MIRROR of PostGIS, never the position of record. The API role is
 *    refused access to `monad_gis` on purpose (roles/monad_api/tasks/database.yml), so the
 *    backend learns where a card or a node stands only through `lab_placements_write`, which
 *    replaces one floor per call and stamps `synced_at`/`sync_id`. Staleness is displayed on the
 *    placement board, not hidden.
 *
 * 2. `notifications` / `notification_deliveries` — one row per composed notification, one
 *    delivery row per recipient. `push_status` records what the worker did for that user
 *    (skipped | queued | sent | failed); `read_at` is the inbox read mark. Nothing is edited
 *    after send.
 *
 * 3. `push_tokens` — one FCM token per app installation per user. Deleted on logout and on
 *    account deletion so a phone that changes hands does not receive the previous owner's inbox.
 *
 * 4. `beta_signups` — the `/join` register. Email is unique CASE-INSENSITIVELY through an index
 *    on lower(email); `withdrawn` scrubs email, name and notes in place and keeps the dates, the
 *    same posture as User::softDelete().
 *
 * 5. `users.notify_general` (default TRUE), `users.notify_callouts` (default FALSE: a quest
 *    callout is promotional content under App Store Review Guideline 4.5.4 and needs an explicit
 *    in-app opt-in), `users.cohort` (`beta` or NULL).
 *
 * Timestamps here are TIMESTAMPTZ (mapped as datetimetz_immutable), as the proposal's SQL states,
 * unlike the older tables' TIMESTAMP WITHOUT TIME ZONE.
 */
final class Version20260916110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'IP-157: lab_placements, notifications, notification_deliveries, push_tokens, beta_signups, users notification columns';
    }

    public function up(Schema $schema): void
    {
        // ── 1. placement mirror ──────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE lab_placements (
              id            UUID PRIMARY KEY,
              key           TEXT NOT NULL,
              kind          VARCHAR(8) NOT NULL,
              floor         TEXT NOT NULL,
              layer         TEXT NOT NULL,
              room          TEXT,
              x_m           DOUBLE PRECISION NOT NULL,
              y_m           DOUBLE PRECISION NOT NULL,
              z_cm          DOUBLE PRECISION,
              provenance    TEXT,
              source_updated_at TIMESTAMPTZ,
              synced_at     TIMESTAMPTZ NOT NULL,
              sync_id       UUID NOT NULL,
              UNIQUE (floor, key)
            )
            SQL);
        $this->addSql("COMMENT ON COLUMN lab_placements.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN lab_placements.sync_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN lab_placements.source_updated_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN lab_placements.synced_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql(
            "COMMENT ON TABLE lab_placements IS "
            ."'Mirror of the PostGIS placement layers (IP-157), replaced per floor by lab_placements_write. "
            ."NOT the position of record: monad_gis is. kind is card|node; key is MONAD-FP-07 or monad03.'"
        );

        // ── 2. notifications ─────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
              id            UUID PRIMARY KEY,
              type          VARCHAR(16) NOT NULL,
              title         VARCHAR(120) NOT NULL,
              body          TEXT NOT NULL,
              quest_id      UUID REFERENCES quests(id) ON DELETE SET NULL,
              audience      VARCHAR(16) NOT NULL,
              deep_link     VARCHAR(512),
              push          BOOLEAN NOT NULL DEFAULT TRUE,
              scheduled_for TIMESTAMPTZ,
              sent_at       TIMESTAMPTZ,
              expires_at    TIMESTAMPTZ,
              created_by_id UUID NOT NULL REFERENCES users(id),
              created_at    TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_notifications_quest ON notifications (quest_id)');
        $this->addSql('CREATE INDEX IDX_notifications_created_by ON notifications (created_by_id)');
        $this->addSql('CREATE INDEX notification_scheduled_idx ON notifications (scheduled_for)');
        $this->addSql("COMMENT ON COLUMN notifications.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN notifications.quest_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN notifications.created_by_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN notifications.scheduled_for IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN notifications.sent_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN notifications.expires_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN notifications.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql(
            "COMMENT ON TABLE notifications IS "
            ."'One composed notification (IP-157). type is general|quest_callout, audience is all|beta|operators. "
            ."Stored and served through the inbox; pushed only to opted-in users with a token. Never edited after send.'"
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE notification_deliveries (
              id              UUID PRIMARY KEY,
              notification_id UUID NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
              user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              push_status     VARCHAR(16) NOT NULL,
              push_error      TEXT,
              delivered_at    TIMESTAMPTZ,
              read_at         TIMESTAMPTZ,
              UNIQUE (notification_id, user_id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_notification_deliveries_user ON notification_deliveries (user_id)');
        $this->addSql("COMMENT ON COLUMN notification_deliveries.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN notification_deliveries.notification_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN notification_deliveries.user_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN notification_deliveries.delivered_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN notification_deliveries.read_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql(
            "COMMENT ON TABLE notification_deliveries IS "
            ."'One recipient of one notification (IP-157). push_status is skipped|queued|sent|failed; read_at is the inbox read mark.'"
        );

        // ── 3. push tokens ───────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE push_tokens (
              id           UUID PRIMARY KEY,
              user_id      UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              handset_id   UUID REFERENCES handsets(id) ON DELETE SET NULL,
              platform     VARCHAR(8) NOT NULL,
              token        TEXT NOT NULL UNIQUE,
              created_at   TIMESTAMPTZ NOT NULL,
              last_seen_at TIMESTAMPTZ NOT NULL,
              revoked_at   TIMESTAMPTZ
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_push_tokens_user ON push_tokens (user_id)');
        $this->addSql('CREATE INDEX IDX_push_tokens_handset ON push_tokens (handset_id)');
        $this->addSql("COMMENT ON COLUMN push_tokens.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN push_tokens.user_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN push_tokens.handset_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN push_tokens.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN push_tokens.last_seen_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN push_tokens.revoked_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql(
            "COMMENT ON TABLE push_tokens IS "
            ."'One FCM registration token per app installation per user (IP-157). platform is ios|android. "
            ."Registered after login, deleted on logout and account deletion.'"
        );

        // ── 4. beta signups ──────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE beta_signups (
              id             UUID PRIMARY KEY,
              email          VARCHAR(180) NOT NULL,
              name           VARCHAR(80),
              platform       VARCHAR(8) NOT NULL,
              availability   VARCHAR(12) NOT NULL,
              consent_at     TIMESTAMPTZ NOT NULL,
              consent_version VARCHAR(16) NOT NULL,
              updates_opt_in BOOLEAN NOT NULL DEFAULT FALSE,
              source         VARCHAR(32),
              status         VARCHAR(12) NOT NULL,
              invited_at     TIMESTAMPTZ,
              invited_by_id  UUID REFERENCES users(id),
              registered_at  TIMESTAMPTZ,
              user_id        UUID REFERENCES users(id) ON DELETE SET NULL,
              notes          TEXT,
              created_at     TIMESTAMPTZ NOT NULL,
              withdrawn_at   TIMESTAMPTZ
            )
            SQL);
        // Unique case-insensitively: a student who signs up as Jana@stuba.sk and again as
        // jana@stuba.sk is one person, and the register-time link in AuthController compares
        // lower(email) as well.
        $this->addSql('CREATE UNIQUE INDEX beta_signup_email_lower_idx ON beta_signups (lower(email))');
        $this->addSql('CREATE INDEX beta_signup_status_idx ON beta_signups (status)');
        $this->addSql('CREATE INDEX IDX_beta_signups_invited_by ON beta_signups (invited_by_id)');
        $this->addSql('CREATE INDEX IDX_beta_signups_user ON beta_signups (user_id)');
        $this->addSql("COMMENT ON COLUMN beta_signups.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN beta_signups.invited_by_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN beta_signups.user_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN beta_signups.consent_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN beta_signups.invited_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN beta_signups.registered_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN beta_signups.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN beta_signups.withdrawn_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql(
            "COMMENT ON TABLE beta_signups IS "
            ."'The /join register (IP-157). status is new|invited|registered|declined|withdrawn; platform ios|android|unsure; "
            ."availability yes|sometimes|remote. withdrawn scrubs email, name and notes in place and keeps the dates.'"
        );

        // ── 5. per-user notification preferences and cohort ──────────────────
        $this->addSql('ALTER TABLE users ADD notify_general BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE users ADD notify_callouts BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE users ADD cohort VARCHAR(16) DEFAULT NULL');
        $this->addSql(
            "COMMENT ON COLUMN users.notify_callouts IS "
            ."'Explicit opt-in (IP-157). A quest callout is promotional under App Store Review Guideline 4.5.4, so it is off until the user says otherwise in the app.'"
        );
        $this->addSql("COMMENT ON COLUMN users.cohort IS 'beta when the account registered against a beta_signups row, else NULL (IP-157).'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP cohort');
        $this->addSql('ALTER TABLE users DROP notify_callouts');
        $this->addSql('ALTER TABLE users DROP notify_general');
        $this->addSql('DROP TABLE beta_signups');
        $this->addSql('DROP TABLE push_tokens');
        $this->addSql('DROP TABLE notification_deliveries');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE lab_placements');
    }
}
