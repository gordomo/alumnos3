<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220816140311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alumnos_pagos ADD curso_id INT DEFAULT NULL, DROP cursos_id');
        $this->addSql('ALTER TABLE alumnos_pagos ADD CONSTRAINT FK_FBAE77F687CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)');
        $this->addSql('CREATE INDEX IDX_FBAE77F687CB4A1F ON alumnos_pagos (curso_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alumnos_pagos DROP FOREIGN KEY FK_FBAE77F687CB4A1F');
        $this->addSql('DROP INDEX IDX_FBAE77F687CB4A1F ON alumnos_pagos');
        $this->addSql('ALTER TABLE alumnos_pagos ADD cursos_id INT NOT NULL, DROP curso_id');
    }
}
