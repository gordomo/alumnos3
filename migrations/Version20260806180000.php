<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Areas de evaluacion: los ejes sobre los que se califica (Writing, Listening, Speaking).
 *
 * Con esto la libreta pasa a ser una grilla de areas por periodos, donde cada celda es el
 * promedio de las evaluaciones de esa area en ese periodo. El profesor sigue cargando
 * evaluaciones como siempre.
 *
 * Aditivo. Un instituto sin areas cargadas funciona igual que antes: las evaluaciones quedan
 * con area_id en null.
 */
final class Version20260806180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Areas de evaluacion por instituto y su vinculo con las evaluaciones";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE area_evaluacion (id INT AUTO_INCREMENT NOT NULL, configuracion_id INT NOT NULL, instituto_id INT NOT NULL, nombre VARCHAR(100) NOT NULL, abreviatura VARCHAR(20) DEFAULT NULL, icono VARCHAR(40) DEFAULT NULL, orden INT NOT NULL, activo TINYINT(1) DEFAULT 1 NOT NULL, INDEX IDX_511D28E9D18A8F98 (configuracion_id), INDEX IDX_511D28E96C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("ALTER TABLE area_evaluacion ADD CONSTRAINT FK_511D28E9D18A8F98 FOREIGN KEY (configuracion_id) REFERENCES instituto_configuracion (id)");
        $this->addSql("ALTER TABLE area_evaluacion ADD CONSTRAINT FK_511D28E96C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)");
        $this->addSql("ALTER TABLE evaluacion ADD area_id INT DEFAULT NULL");
        $this->addSql("ALTER TABLE evaluacion ADD CONSTRAINT FK_DEEDCA53BD0F409C FOREIGN KEY (area_id) REFERENCES area_evaluacion (id) ON DELETE SET NULL");
        $this->addSql("CREATE INDEX IDX_DEEDCA53BD0F409C ON evaluacion (area_id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE evaluacion DROP FOREIGN KEY FK_DEEDCA53BD0F409C");
        $this->addSql("DROP INDEX IDX_DEEDCA53BD0F409C ON evaluacion");
        $this->addSql("ALTER TABLE evaluacion DROP area_id");
        $this->addSql("DROP TABLE area_evaluacion");
    }
}
