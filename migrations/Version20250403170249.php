<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250403170249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE profesor ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE profesor ADD CONSTRAINT FK_5B7406D9A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5B7406D9A76ED395 ON profesor (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE profesor DROP FOREIGN KEY FK_5B7406D9A76ED395');
        $this->addSql('DROP INDEX UNIQ_5B7406D9A76ED395 ON profesor');
        $this->addSql('ALTER TABLE profesor DROP user_id');
    }
}
