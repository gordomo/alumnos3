<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220811184202 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alumnos_pagos (id INT AUTO_INCREMENT NOT NULL, alumno_id INT NOT NULL, fecha DATE NOT NULL, INDEX IDX_FBAE77F6FC28E5EE (alumno_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE alumnos_pagos ADD CONSTRAINT FK_FBAE77F6FC28E5EE FOREIGN KEY (alumno_id) REFERENCES alumno (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE alumnos_pagos');
        $this->addSql('DROP INDEX UNIQ_1435D52D7F8F253B ON alumno');
    }
}
