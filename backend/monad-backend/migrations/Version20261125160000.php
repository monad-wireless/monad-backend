<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Live quests: a step whose screen the server drives (2026-11-25).
 *
 * 1. `quest_live_runs`: one row per step completion of a `live` step, holding the engine's state.
 * 2. `quest_live_events`: append-only. Everything the phone reported and everything the engine
 *    decided, including each hint as it was served. It is the record a guidance trial is read from.
 * 3. `quest_live_leases`: which run holds which resource of a pool, and how often each resource
 *    was assigned, so concurrent players are sent to different places and the places are balanced.
 * 4. `live_sightings`: what the nodes hear right now, for phones with an active live run only.
 *    UNLOGGED: it is a minutes-long buffer, and the BLE archive on S3 is the record.
 *
 * Additive, on the rule Version20260901150000 set (`docker/entrypoint.sh` migrates on boot).
 */
final class Version20261125160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Live quests: quest_live_runs, quest_live_events, quest_live_leases, live_sightings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quest_live_runs (
              id                  UUID PRIMARY KEY,
              step_completion_id  UUID NOT NULL REFERENCES quest_step_completions (id) ON DELETE CASCADE,
              engine              VARCHAR(64) NOT NULL,
              state               JSONB NOT NULL,
              subject             UUID DEFAULT NULL,
              done                BOOLEAN DEFAULT FALSE NOT NULL,
              revision            INT DEFAULT 0 NOT NULL,
              created_at          TIMESTAMPTZ NOT NULL,
              updated_at          TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX quest_live_runs_completion ON quest_live_runs (step_completion_id)');
        $this->addSql('CREATE INDEX quest_live_runs_subject ON quest_live_runs (subject) WHERE done = FALSE');

        $this->addSql(<<<'SQL'
            CREATE TABLE quest_live_events (
              id               BIGSERIAL PRIMARY KEY,
              run_id           UUID NOT NULL REFERENCES quest_live_runs (id) ON DELETE CASCADE,
              client_event_id  VARCHAR(64) DEFAULT NULL,
              origin           VARCHAR(8) NOT NULL,
              kind             VARCHAR(48) NOT NULL,
              payload          JSONB NOT NULL,
              client_wall_ms   BIGINT DEFAULT NULL,
              client_mono_ns   BIGINT DEFAULT NULL,
              at               TIMESTAMPTZ(3) NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX quest_live_events_client ON quest_live_events (run_id, client_event_id)');
        $this->addSql('CREATE INDEX quest_live_events_run ON quest_live_events (run_id, id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE quest_live_leases (
              pool            VARCHAR(96) NOT NULL,
              resource        VARCHAR(96) NOT NULL,
              run_id          UUID DEFAULT NULL REFERENCES quest_live_runs (id) ON DELETE SET NULL,
              expires_at      TIMESTAMPTZ DEFAULT NULL,
              assigned_count  INT DEFAULT 0 NOT NULL,
              PRIMARY KEY (pool, resource)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNLOGGED TABLE live_sightings (
              subject  UUID NOT NULL,
              node     VARCHAR(32) NOT NULL,
              seen_at  TIMESTAMPTZ(3) NOT NULL,
              rssi     SMALLINT NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX live_sightings_subject ON live_sightings (subject, seen_at)');
        $this->addSql('CREATE INDEX live_sightings_seen ON live_sightings (seen_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE live_sightings');
        $this->addSql('DROP TABLE quest_live_leases');
        $this->addSql('DROP TABLE quest_live_events');
        $this->addSql('DROP TABLE quest_live_runs');
    }
}
