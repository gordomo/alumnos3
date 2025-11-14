<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251114193540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE instituto_admin (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, user_id INT NOT NULL, activo TINYINT(1) NOT NULL, INDEX IDX_CB4C9E96C6EF28 (instituto_id), INDEX IDX_CB4C9E9A76ED395 (user_id), UNIQUE INDEX unique_instituto_user (instituto_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE instituto_admin ADD CONSTRAINT FK_CB4C9E96C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE instituto_admin ADD CONSTRAINT FK_CB4C9E9A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituto_admin DROP FOREIGN KEY FK_CB4C9E96C6EF28');
        $this->addSql('ALTER TABLE instituto_admin DROP FOREIGN KEY FK_CB4C9E9A76ED395');
        $this->addSql('DROP TABLE instituto_admin');
    }
}
