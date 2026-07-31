<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calificaciones: escala configurable por instituto, evaluaciones por curso y notas.
 *
 * Todo aditivo. Las columnas nuevas NOT NULL de instituto_configuracion traen DEFAULT, asi
 * que las filas existentes quedan con modo_calificacion = ninguno: la feature nace apagada
 * y el cierre de curso sigue decidiendose solo por asistencia y pago.
 */
final class Version20260731110150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Calificaciones: escala del instituto, evaluaciones y notas";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE calificacion (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, evaluacion_id INT NOT NULL, alumno_curso_historico_id INT NOT NULL, concepto_id INT DEFAULT NULL, cargado_por_id INT DEFAULT NULL, valor_numerico NUMERIC(6, 2) DEFAULT NULL, ausente TINYINT(1) DEFAULT 0 NOT NULL, observaciones LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_8A3AF218E715F406 (evaluacion_id), INDEX IDX_8A3AF2186C2330BD (concepto_id), INDEX IDX_8A3AF218E87641F9 (cargado_por_id), INDEX idx_calificacion_hist (alumno_curso_historico_id), INDEX idx_calificacion_instituto (instituto_id), UNIQUE INDEX uniq_calificacion_eval_hist (evaluacion_id, alumno_curso_historico_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE concepto_calificacion (id INT AUTO_INCREMENT NOT NULL, configuracion_id INT NOT NULL, instituto_id INT NOT NULL, nombre VARCHAR(100) NOT NULL, abreviatura VARCHAR(10) DEFAULT NULL, orden INT NOT NULL, aprueba TINYINT(1) NOT NULL, equivalente_numerico NUMERIC(6, 2) DEFAULT NULL, activo TINYINT(1) NOT NULL, INDEX IDX_29456C5DD18A8F98 (configuracion_id), INDEX IDX_29456C5D6C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE evaluacion (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, curso_id INT NOT NULL, creado_por_id INT DEFAULT NULL, nombre VARCHAR(150) NOT NULL, fecha DATE NOT NULL, descripcion LONGTEXT DEFAULT NULL, peso NUMERIC(5, 2) DEFAULT \'1.00\' NOT NULL, cuenta_para_promedio TINYINT(1) DEFAULT 1 NOT NULL, tipo_escala VARCHAR(20) DEFAULT NULL, nota_minima NUMERIC(6, 2) DEFAULT NULL, nota_maxima NUMERIC(6, 2) DEFAULT NULL, nota_aprobacion NUMERIC(6, 2) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_DEEDCA5387CB4A1F (curso_id), INDEX IDX_DEEDCA53FE35D8C4 (creado_por_id), INDEX idx_evaluacion_curso_fecha (curso_id, fecha), INDEX idx_evaluacion_instituto (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE calificacion ADD CONSTRAINT FK_8A3AF2186C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE calificacion ADD CONSTRAINT FK_8A3AF218E715F406 FOREIGN KEY (evaluacion_id) REFERENCES evaluacion (id)');
        $this->addSql('ALTER TABLE calificacion ADD CONSTRAINT FK_8A3AF2181D62FD39 FOREIGN KEY (alumno_curso_historico_id) REFERENCES alumno_curso_historico (id)');
        $this->addSql('ALTER TABLE calificacion ADD CONSTRAINT FK_8A3AF2186C2330BD FOREIGN KEY (concepto_id) REFERENCES concepto_calificacion (id)');
        $this->addSql('ALTER TABLE calificacion ADD CONSTRAINT FK_8A3AF218E87641F9 FOREIGN KEY (cargado_por_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE concepto_calificacion ADD CONSTRAINT FK_29456C5DD18A8F98 FOREIGN KEY (configuracion_id) REFERENCES instituto_configuracion (id)');
        $this->addSql('ALTER TABLE concepto_calificacion ADD CONSTRAINT FK_29456C5D6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE evaluacion ADD CONSTRAINT FK_DEEDCA536C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE evaluacion ADD CONSTRAINT FK_DEEDCA5387CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)');
        $this->addSql('ALTER TABLE evaluacion ADD CONSTRAINT FK_DEEDCA53FE35D8C4 FOREIGN KEY (creado_por_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE alumno_curso_historico ADD resultado_notas VARCHAR(20) DEFAULT NULL, ADD promedio_notas NUMERIC(6, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE instituto_configuracion ADD modo_calificacion VARCHAR(20) DEFAULT \'ninguno\' NOT NULL, ADD nota_minima NUMERIC(6, 2) DEFAULT NULL, ADD nota_maxima NUMERIC(6, 2) DEFAULT NULL, ADD nota_aprobacion NUMERIC(6, 2) DEFAULT NULL, ADD notas_influyen_aprobacion TINYINT(1) DEFAULT 0 NOT NULL, ADD criterio_aprobacion_notas VARCHAR(20) DEFAULT \'promedio\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE calificacion DROP FOREIGN KEY FK_8A3AF2186C6EF28');
        $this->addSql('ALTER TABLE calificacion DROP FOREIGN KEY FK_8A3AF218E715F406');
        $this->addSql('ALTER TABLE calificacion DROP FOREIGN KEY FK_8A3AF2181D62FD39');
        $this->addSql('ALTER TABLE calificacion DROP FOREIGN KEY FK_8A3AF2186C2330BD');
        $this->addSql('ALTER TABLE calificacion DROP FOREIGN KEY FK_8A3AF218E87641F9');
        $this->addSql('ALTER TABLE concepto_calificacion DROP FOREIGN KEY FK_29456C5DD18A8F98');
        $this->addSql('ALTER TABLE concepto_calificacion DROP FOREIGN KEY FK_29456C5D6C6EF28');
        $this->addSql('ALTER TABLE evaluacion DROP FOREIGN KEY FK_DEEDCA536C6EF28');
        $this->addSql('ALTER TABLE evaluacion DROP FOREIGN KEY FK_DEEDCA5387CB4A1F');
        $this->addSql('ALTER TABLE evaluacion DROP FOREIGN KEY FK_DEEDCA53FE35D8C4');
        $this->addSql('DROP TABLE calificacion');
        $this->addSql('DROP TABLE concepto_calificacion');
        $this->addSql('DROP TABLE evaluacion');
        $this->addSql('ALTER TABLE alumno_curso_historico DROP resultado_notas, DROP promedio_notas');
        $this->addSql('ALTER TABLE instituto_configuracion DROP modo_calificacion, DROP nota_minima, DROP nota_maxima, DROP nota_aprobacion, DROP notas_influyen_aprobacion, DROP criterio_aprobacion_notas');
    }
}
