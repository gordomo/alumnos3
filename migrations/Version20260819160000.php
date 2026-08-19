<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Modulo de tareas: la tarea que se le pide a un curso y la entrega de cada alumno.
 *
 * Es la ultima pieza de la libreta, que necesita dos numeros por periodo: cuantas tareas se
 * pidieron y cuantas entrego el alumno.
 *
 * Se modela igual que las evaluaciones y sus calificaciones: la tarea pertenece al curso y
 * tiene periodo, y la entrega cuelga de la inscripcion (alumno_curso_historico) y no del
 * alumno, porque pertenece a una cursada concreta.
 *
 * Todo aditivo: sin tareas cargadas nada cambia.
 */
final class Version20260819160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Tareas por curso y entregas por inscripcion";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE tarea (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, curso_id INT NOT NULL, periodo_id INT DEFAULT NULL, creado_por_id INT DEFAULT NULL, titulo VARCHAR(150) NOT NULL, fecha DATE NOT NULL, fecha_entrega DATE DEFAULT NULL, descripcion LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_3CA0536687CB4A1F (curso_id), INDEX IDX_3CA053669C3921AB (periodo_id), INDEX IDX_3CA05366FE35D8C4 (creado_por_id), INDEX idx_tarea_curso_fecha (curso_id, fecha), INDEX idx_tarea_instituto (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE tarea_entrega (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, tarea_id INT NOT NULL, alumno_curso_historico_id INT NOT NULL, cargado_por_id INT DEFAULT NULL, entregada TINYINT(1) DEFAULT 0 NOT NULL, observaciones LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, INDEX IDX_E851A6696D5BDFE1 (tarea_id), INDEX IDX_E851A669E87641F9 (cargado_por_id), INDEX idx_entrega_hist (alumno_curso_historico_id), INDEX idx_entrega_instituto (instituto_id), UNIQUE INDEX uniq_entrega_tarea_hist (tarea_id, alumno_curso_historico_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("ALTER TABLE tarea_entrega ADD CONSTRAINT FK_E851A6696C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)");
        $this->addSql("ALTER TABLE tarea_entrega ADD CONSTRAINT FK_E851A6696D5BDFE1 FOREIGN KEY (tarea_id) REFERENCES tarea (id)");
        $this->addSql("ALTER TABLE tarea_entrega ADD CONSTRAINT FK_E851A6691D62FD39 FOREIGN KEY (alumno_curso_historico_id) REFERENCES alumno_curso_historico (id)");
        $this->addSql("ALTER TABLE tarea_entrega ADD CONSTRAINT FK_E851A669E87641F9 FOREIGN KEY (cargado_por_id) REFERENCES user (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE tarea ADD CONSTRAINT FK_3CA053666C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)");
        $this->addSql("ALTER TABLE tarea ADD CONSTRAINT FK_3CA0536687CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)");
        $this->addSql("ALTER TABLE tarea ADD CONSTRAINT FK_3CA053669C3921AB FOREIGN KEY (periodo_id) REFERENCES periodo_academico (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE tarea ADD CONSTRAINT FK_3CA05366FE35D8C4 FOREIGN KEY (creado_por_id) REFERENCES user (id) ON DELETE SET NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP TABLE tarea_entrega");
        $this->addSql("DROP TABLE tarea");
    }
}
