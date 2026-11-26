<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop `news` and `qr_codes` (IP-157, removal).
 *
 * The 2026-09-16 audit found no reader for either table: no API route, DTO, template or test
 * read them, and `qr_codes` contradicted the marker rule that a marker exists only because a
 * quest step names it (MarkerService). The entities, repositories, CRUD controllers, menu items
 * and fixture branches went with them in the same change.
 *
 * `down()` recreates both tables with the exact statements of Version20251111174124, so a
 * rollback restores the schema (not the rows). The deploy notes print the production row counts
 * before this runs on boot.
 */
final class Version20260916100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'IP-157: drop the unread news and qr_codes tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS "news"');
        $this->addSql('DROP TABLE IF EXISTS "qr_codes"');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "news" (id UUID NOT NULL, created_by_id UUID NOT NULL, title VARCHAR(255) NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_1DD39950B03A8386 ON "news" (created_by_id)');
        $this->addSql('COMMENT ON COLUMN "news".id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN "news".created_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN "news".created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "news".updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE "qr_codes" (id UUID NOT NULL, created_by_id UUID NOT NULL, name VARCHAR(255) NOT NULL, position TEXT NOT NULL, value VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A07803171D775834 ON "qr_codes" (value)');
        $this->addSql('CREATE INDEX IDX_A0780317B03A8386 ON "qr_codes" (created_by_id)');
        $this->addSql('COMMENT ON COLUMN "qr_codes".id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN "qr_codes".created_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN "qr_codes".created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "qr_codes".updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE "news" ADD CONSTRAINT FK_1DD39950B03A8386 FOREIGN KEY (created_by_id) REFERENCES "users" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "qr_codes" ADD CONSTRAINT FK_A0780317B03A8386 FOREIGN KEY (created_by_id) REFERENCES "users" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
