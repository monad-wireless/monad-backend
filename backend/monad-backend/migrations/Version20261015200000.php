<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `quest_revisions.created_at` holds whole seconds.
 *
 * Version20261015120000 backfilled revision 1 with `now()`, which carries microseconds, and
 * Doctrine's `datetimetz_immutable` reads only `Y-m-d H:i:sO`. Every migrated revision then
 * failed to hydrate and `lab_quest_revisions` answered "Error while executing tool" in
 * production (Loki, 2026-10-15 21:23 UTC). TIMESTAMPTZ(0) rounds the stored values and every
 * later write, so the column and the mapping agree.
 */
final class Version20261015200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'quest_revisions.created_at: TIMESTAMPTZ(0), the precision Doctrine datetimetz reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_revisions ALTER COLUMN created_at TYPE TIMESTAMPTZ(0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_revisions ALTER COLUMN created_at TYPE TIMESTAMPTZ');
    }
}
