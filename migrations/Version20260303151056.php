<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabla curso_horario: un curso puede tener varios horarios por día (ej. Martes 18-19, Jueves 18:30-19:30).
 * Se migran los datos existentes: por cada curso se crea un registro en curso_horario por cada día en curso.dias
 * con el mismo horario_inicio y horario_fin del curso.
 */
final class Version20260303151056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crear curso_horario y migrar horarios desde curso (dias + horario_inicio/fin)';
    }

    public function up(Schema $schema): void
    {
        // Ejecutar DDL con la misma conexión para que la tabla exista antes de los INSERT
        $this->connection->executeStatement(
            'CREATE TABLE curso_horario (id INT AUTO_INCREMENT NOT NULL, curso_id INT NOT NULL, dia VARCHAR(20) NOT NULL, horario_inicio TIME NOT NULL, horario_fin TIME NOT NULL, duracion DOUBLE PRECISION DEFAULT NULL, INDEX IDX_CB7CF09D87CB4A1F (curso_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
        $this->connection->executeStatement(
            'ALTER TABLE curso_horario ADD CONSTRAINT FK_CB7CF09D87CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE CASCADE'
        );

        $this->migrateDatosCursoHorario();
    }

    private function migrateDatosCursoHorario(): void
    {
        $cursos = $this->connection->fetchAllAssociative('SELECT id, dias, horario_inicio, horario_fin FROM curso');
        foreach ($cursos as $row) {
            $dias = $row['dias'] !== null ? json_decode($row['dias'], true) : null;
            if (!is_array($dias) || count($dias) === 0) {
                continue;
            }
            foreach ($dias as $dia) {
                $this->connection->executeStatement(
                    'INSERT INTO curso_horario (curso_id, dia, horario_inicio, horario_fin) VALUES (?, ?, ?, ?)',
                    [$row['id'], $dia, $row['horario_inicio'], $row['horario_fin']]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('ALTER TABLE curso_horario DROP FOREIGN KEY FK_CB7CF09D87CB4A1F');
        $this->connection->executeStatement('DROP TABLE curso_horario');
    }
}
