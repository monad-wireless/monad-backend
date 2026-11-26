<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A finished run carries its own configuration, so a quest can be edited afterwards.
 *
 * THE PROBLEM THIS REMOVES. `quest_step_completions.step_id` was NOT NULL, which made the
 * step ROW the record of what a participant had been asked to do. Rewriting a quest deletes
 * and recreates its steps, so the database refused the delete for any quest somebody had
 * run. `lab_quest_write` therefore could not touch a live quest at all, and the only way to
 * change one was to publish a new quest and hide the old — which is why the catalogue holds
 * four "(retired 2026-08)" generations of the same five quests. A foreign key was setting
 * editorial policy, and the archive it was protecting was never actually safe: the row it
 * pinned could still be EDITED, silently re-pointing finished runs at text nobody walked.
 *
 * WHAT REPLACES IT. `step_snapshot` freezes the step as that enrollment was served it —
 * `{order, name, type, config}`, with `order` being the position in the realised sequence,
 * which for a pooled hunt is not the declared order (IP-145). The same posture as the
 * IP-149 handset snapshot: the evidence for a run is per-run and verbatim, never normalised
 * and never a join to something that can move.
 *
 * `step_id` survives as a nullable pointer with ON DELETE SET NULL, so grouping completions
 * by a step that still exists keeps working and deleting one costs a join rather than a
 * fact. `enrollment_step_idx` stays: Postgres allows repeated NULLs in a unique index, so a
 * quest whose steps were replaced does not collide with itself.
 *
 * ADDITIVE AND NULLABLE, on the rule Version20260901150000 set: `docker/entrypoint.sh`
 * migrates on container boot, so every migration is a chance to take the API down. Existing
 * rows get no snapshot and stay readable through `QuestStepCompletion::describeStep()`,
 * which falls back to the live row. Backfilling them would be a lie — nobody recorded what
 * those steps said at the time, and inventing it from today's rows is exactly the error this
 * column exists to prevent.
 */
final class Version20260920180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'quest_step_completions: freeze the served step per run; step_id nullable ON DELETE SET NULL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_step_completions ADD step_snapshot JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_step_completions ALTER COLUMN step_id DROP NOT NULL');

        // The constraint name Doctrine generated for the step_id FK is not stable across
        // environments, so it is looked up rather than assumed. Dropping and re-adding is
        // the only way to change ON DELETE on Postgres.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                fk_name text;
            BEGIN
                SELECT con.conname INTO fk_name
                FROM pg_constraint con
                JOIN pg_class rel ON rel.oid = con.conrelid
                JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = con.conkey[1]
                WHERE con.contype = 'f'
                  AND rel.relname = 'quest_step_completions'
                  AND att.attname = 'step_id'
                  AND array_length(con.conkey, 1) = 1;

                IF fk_name IS NOT NULL THEN
                    EXECUTE format('ALTER TABLE quest_step_completions DROP CONSTRAINT %I', fk_name);
                END IF;

                ALTER TABLE quest_step_completions
                    ADD CONSTRAINT fk_qsc_step_id
                    FOREIGN KEY (step_id) REFERENCES quest_steps (id) ON DELETE SET NULL;
            END $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversing this needs every completion to point at a live step again. A quest
        // rewritten while the column existed has completions whose step_id is NULL and
        // whose only record is the snapshot, so restoring NOT NULL would have to delete
        // them. Refuse instead of destroying the archive this migration exists to keep.
        $this->throwIrreversibleMigrationException(
            'Irreversible: completions whose step was deleted carry their evidence only in '
            . 'step_snapshot, and restoring NOT NULL on step_id would delete those rows.'
        );
    }
}
