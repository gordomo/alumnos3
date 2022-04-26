<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220426190008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE profesor_curso (profesor_id INT NOT NULL, curso_id INT NOT NULL, INDEX IDX_7EC6E2ADE52BD977 (profesor_id), INDEX IDX_7EC6E2AD87CB4A1F (curso_id), PRIMARY KEY(profesor_id, curso_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE profesor_curso ADD CONSTRAINT FK_7EC6E2ADE52BD977 FOREIGN KEY (profesor_id) REFERENCES profesor (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE profesor_curso ADD CONSTRAINT FK_7EC6E2AD87CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE profesor_curso');
    }
}
