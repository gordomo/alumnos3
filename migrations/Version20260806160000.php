<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Periodos academicos: trimestres, bimestres y mesas de examen por instituto.
 *
 * Todo aditivo. Un instituto sin periodos cargados funciona igual que antes: las
 * evaluaciones quedan con periodo_id en null y el boletin sigue de una sola columna.
 *
 * Los periodos se definen por rango de meses y no por fechas con anio, asi se configuran una
 * vez y sirven todos los anios.
 */
final class Version20260806160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Periodos academicos por instituto y su vinculo con las evaluaciones";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE periodo_academico (id INT AUTO_INCREMENT NOT NULL, configuracion_id INT NOT NULL, instituto_id INT NOT NULL, nombre VARCHAR(100) NOT NULL, abreviatura VARCHAR(20) DEFAULT NULL, tipo VARCHAR(20) DEFAULT 'cursada' NOT NULL, mes_inicio INT NOT NULL, mes_fin INT NOT NULL, orden INT NOT NULL, activo TINYINT(1) DEFAULT 1 NOT NULL, INDEX IDX_C6BC86FD18A8F98 (configuracion_id), INDEX IDX_C6BC86F6C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("ALTER TABLE periodo_academico ADD CONSTRAINT FK_C6BC86FD18A8F98 FOREIGN KEY (configuracion_id) REFERENCES instituto_configuracion (id)");
        $this->addSql("ALTER TABLE periodo_academico ADD CONSTRAINT FK_C6BC86F6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)");
        $this->addSql("ALTER TABLE evaluacion ADD periodo_id INT DEFAULT NULL");
        $this->addSql("ALTER TABLE evaluacion ADD CONSTRAINT FK_DEEDCA539C3921AB FOREIGN KEY (periodo_id) REFERENCES periodo_academico (id) ON DELETE SET NULL");
        $this->addSql("CREATE INDEX IDX_DEEDCA539C3921AB ON evaluacion (periodo_id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE evaluacion DROP FOREIGN KEY FK_DEEDCA539C3921AB");
        $this->addSql("DROP INDEX IDX_DEEDCA539C3921AB ON evaluacion");
        $this->addSql("ALTER TABLE evaluacion DROP periodo_id");
        $this->addSql("DROP TABLE periodo_academico");
    }
}
