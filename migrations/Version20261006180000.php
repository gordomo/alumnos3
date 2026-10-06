<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Qué hace el fijo mensual del profesor con los cursos que tienen regla propia.
 *
 * Nace en 1 -se suma- que es lo que el sistema venía haciendo, así que ninguna liquidación
 * cambia de monto por esta migración.
 */
final class Version20261006180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Columna profesor.fijo_se_suma';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profesor ADD fijo_se_suma TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profesor DROP fijo_se_suma');
    }
}
