<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One quest per name, edited in place, with a revision history (2026-10-15).
 *
 * 1. `quests.name` becomes unique. The app lists rows, so two rows named "Counting" are two
 *    Counting quests on a phone. The catalogue's earlier generations carry distinct names
 *    ("Counting (retired 2026-08)") and stay as hidden rows with their runs.
 * 2. `quests.revision` counts published step sets. Every existing quest gets revision 1 and one
 *    `quest_revisions` row holding its CURRENT steps, stamped source `migration` and the
 *    migration time. That records what the quest says today, not what it said before: earlier
 *    step sets were never recorded, and each completion's step_snapshot remains their evidence.
 * 3. `quest_enrollments.quest_revision` records the revision a run was served. NULL for every
 *    existing run, for the same reason.
 *
 * Additive, on the rule Version20260901150000 set (`docker/entrypoint.sh` migrates on boot).
 * The unique index is the one step that can fail on data, so the migration checks first and
 * names the duplicate names instead of letting CREATE UNIQUE INDEX fail with a bare error.
 */
final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quest revisions: unique quest name, quests.revision, quest_revisions, quest_enrollments.quest_revision';
    }

    public function up(Schema $schema): void
    {
        $duplicates = $this->connection->fetchFirstColumn(
            'SELECT name FROM quests GROUP BY name HAVING COUNT(*) > 1 ORDER BY name'
        );
        $this->abortIf(
            $duplicates !== [],
            'quests.name is not unique yet; rename these before migrating: ' . implode(', ', $duplicates),
        );

        $this->addSql('CREATE UNIQUE INDEX quest_name_unique ON quests (name)');
        $this->addSql('ALTER TABLE quests ADD revision INT DEFAULT 0 NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE quest_revisions (
              id                     UUID PRIMARY KEY,
              quest_id               UUID NOT NULL REFERENCES quests (id) ON DELETE CASCADE,
              revision               INT NOT NULL,
              steps                  JSONB NOT NULL,
              required_capabilities  JSONB NOT NULL,
              source                 VARCHAR(16) NOT NULL,
              author_id              UUID REFERENCES users (id) ON DELETE SET NULL,
              created_at             TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX quest_revision_number ON quest_revisions (quest_id, revision)');
        $this->addSql('CREATE INDEX IDX_QUEST_REVISIONS_QUEST ON quest_revisions (quest_id)');
        $this->addSql('CREATE INDEX IDX_QUEST_REVISIONS_AUTHOR ON quest_revisions (author_id)');

        // Revision 1 of every existing quest: its steps as they stand at migration time.
        $this->addSql(<<<'SQL'
            INSERT INTO quest_revisions (id, quest_id, revision, steps, required_capabilities, source, author_id, created_at)
            SELECT gen_random_uuid(), q.id, 1,
                   COALESCE((
                     SELECT jsonb_agg(jsonb_build_object(
                              'order', s."order", 'name', s.name, 'type', s.type, 'config', s.config::jsonb)
                            ORDER BY s."order")
                     FROM quest_steps s WHERE s.quest_id = q.id
                   ), '[]'::jsonb),
                   q.required_capabilities::jsonb, 'migration', NULL, now()
            FROM quests q
            SQL);
        $this->addSql('UPDATE quests SET revision = 1');

        $this->addSql('ALTER TABLE quest_enrollments ADD quest_revision INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_enrollments DROP quest_revision');
        $this->addSql('DROP TABLE quest_revisions');
        $this->addSql('ALTER TABLE quests DROP revision');
        $this->addSql('DROP INDEX quest_name_unique');
    }
}
