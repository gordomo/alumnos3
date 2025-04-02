<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250402142335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update alumno_curso_historico table structure';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alumno_curso_historico DROP meses_adeudados, DROP meses_pagados, DROP precio_mensual');
        $this->addSql('ALTER TABLE alumno_curso_historico ADD activo TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alumno_curso_historico DROP activo');
        $this->addSql('ALTER TABLE alumno_curso_historico ADD meses_adeudados JSON NOT NULL, ADD meses_pagados JSON NOT NULL, ADD precio_mensual DOUBLE PRECISION NOT NULL');
    }
}
