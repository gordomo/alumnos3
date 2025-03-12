<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250310133701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituto ADD logo VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE instituto ADD dir VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE instituto ADD email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE instituto ADD tel VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituto DROP logo');
        $this->addSql('ALTER TABLE instituto DROP dir');
        $this->addSql('ALTER TABLE instituto DROP email');
        $this->addSql('ALTER TABLE instituto DROP tel');
    }
}
