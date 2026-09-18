<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quests declare the device capabilities they require.
 *
 * Defaults to an empty list, which means "any device" — so every existing quest keeps exactly the
 * behaviour it had, and only quests that opt in are filtered.
 */
final class Version20260804060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quests.required_capabilities for device capability negotiation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE quests ADD required_capabilities JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quests DROP required_capabilities');
    }
}
