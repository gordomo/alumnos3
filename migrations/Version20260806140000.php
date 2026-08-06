<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Permite pagos que no pertenecen a un curso, para poder cobrar la cuota de inscripcion anual.
 *
 * alumnos_pagos.curso_historico_id era NOT NULL, asi que un pago sin curso no se podia
 * guardar. La cuota de inscripcion se le cobra al alumno una vez al ano y no pertenece a
 * ninguna materia.
 *
 * Relajar la columna es seguro: getCursoHistorico() sobre un pago se usa en un solo lugar del
 * proyecto (AlumnoCursoHistorico, comparando identidad) y ya tolera null. Los pagos existentes
 * conservan su valor.
 */
final class Version20260806140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "alumnos_pagos.curso_historico_id pasa a nullable (pagos sin curso)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE alumnos_pagos CHANGE curso_historico_id curso_historico_id INT DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        // Volver atras solo es posible si no quedo ningun pago sin curso.
        $this->addSql("ALTER TABLE alumnos_pagos CHANGE curso_historico_id curso_historico_id INT NOT NULL");
    }
}
