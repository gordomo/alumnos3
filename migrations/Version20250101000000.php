<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline del esquema previo a las migraciones.
 *
 * Nueve tablas (instituto, user, alumno, curso, profesor, alumnos_pagos, vencimiento,
 * alumno_curso_historico, deuda_alumno) nunca fueron creadas por ninguna migración:
 * existían ya en la base de producción, creadas antes de que el proyecto usara
 * migraciones. Como siete migraciones posteriores referencian instituto(id),
 * `doctrine:migrations:migrate` sobre una base vacía fallaba con "1215 Cannot add
 * foreign key constraint", así que era imposible levantar el proyecto de cero
 * (entornos nuevos, CI, onboarding).
 *
 * Esta migración crea ese punto de partida. Es idempotente a propósito:
 *  - CREATE TABLE IF NOT EXISTS: en una base que ya tiene las tablas, no hace nada.
 *  - Las foreign keys se agregan solo si el constraint no existe.
 * De modo que aplicarla sobre producción es un no-op seguro.
 *
 * Las columnas que agregan migraciones posteriores SIN guarda de existencia se omiten
 * acá a propósito, para que esos ALTER no choquen con "duplicate column":
 *  - alumno.user_id                (Version20251117181238)
 *  - user.reset_token_sent_at      (Version20251117183722)
 *  - profesor.tipo_pago, monto_fijo_mensual, porcentaje_curso (Version20260312190829)
 *  - curso.cerrado, fecha_cierre   (Version20260427175705)
 *  - vencimiento.configuracion_id  (Version20250511124215)
 *  - alumno_curso_historico.nombre_curso                (Version20260313133945)
 *  - alumno_curso_historico.comenzar_deuda_proximo_mes  (Version20260313154154)
 *  - alumno_curso_historico.modo_generacion_deuda       (Version20260313160546)
 *  - alumno_curso_historico.motivo_baja                 (Version20260427175705)
 *  - deuda_alumno.es_cuota_inscripcion_anual            (Version20260505144746)
 */
final class Version20250101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline: crea las tablas centrales preexistentes para poder migrar desde una base vacía';
    }

    public function up(Schema $schema): void
    {
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB';

        $this->addSql("CREATE TABLE IF NOT EXISTS instituto (
            id INT AUTO_INCREMENT NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            logo VARCHAR(255) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            dir VARCHAR(255) DEFAULT NULL,
            tel VARCHAR(25) DEFAULT NULL,
            PRIMARY KEY(id)) $charset");

        // Sin reset_token_sent_at: lo agrega Version20251117183722.
        $this->addSql("CREATE TABLE IF NOT EXISTS user (
            id INT AUTO_INCREMENT NOT NULL,
            instituto_id INT DEFAULT NULL,
            email VARCHAR(180) NOT NULL,
            roles JSON NOT NULL,
            password VARCHAR(255) NOT NULL,
            reset_token VARCHAR(100) DEFAULT NULL,
            reset_token_expires_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_8D93D649E7927C74 (email),
            INDEX IDX_8D93D6496C6EF28 (instituto_id),
            PRIMARY KEY(id)) $charset");

        // Sin user_id: lo agrega Version20251117181238 (junto con su foreign key).
        $this->addSql("CREATE TABLE IF NOT EXISTS alumno (
            id INT AUTO_INCREMENT NOT NULL,
            instituto_id INT NOT NULL,
            telefono_fijo LONGTEXT DEFAULT NULL,
            nombre LONGTEXT NOT NULL,
            apellido LONGTEXT NOT NULL,
            f_nac DATE DEFAULT NULL,
            email VARCHAR(191) NOT NULL,
            l_nac LONGTEXT DEFAULT NULL,
            dni VARCHAR(8) NOT NULL,
            celular LONGTEXT DEFAULT NULL,
            contacto_emergencia LONGTEXT DEFAULT NULL,
            n_tutor LONGTEXT DEFAULT NULL,
            t_tutor LONGTEXT DEFAULT NULL,
            corre_tutor LONGTEXT DEFAULT NULL,
            dni_tutor LONGTEXT DEFAULT NULL,
            escuela LONGTEXT DEFAULT NULL,
            extras LONGTEXT DEFAULT NULL,
            g_sanguineo LONGTEXT DEFAULT NULL,
            enfermedad LONGTEXT DEFAULT NULL,
            alergico LONGTEXT DEFAULT NULL,
            medicacion LONGTEXT DEFAULT NULL,
            como_conociste LONGTEXT DEFAULT NULL,
            hermanos JSON DEFAULT NULL,
            activo TINYINT(1) DEFAULT NULL,
            UNIQUE INDEX UNIQ_1435D52D7F8F253B (dni),
            INDEX IDX_1435D52D6C6EF28 (instituto_id),
            PRIMARY KEY(id)) $charset");

        // Sin cerrado ni fecha_cierre: los agrega Version20260427175705.
        $this->addSql("CREATE TABLE IF NOT EXISTS curso (
            id INT AUTO_INCREMENT NOT NULL,
            instituto_id INT NOT NULL,
            nombre LONGTEXT NOT NULL,
            dias JSON NOT NULL,
            horario_inicio TIME NOT NULL,
            horario_fin TIME NOT NULL,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE NOT NULL,
            duracion DOUBLE PRECISION NOT NULL,
            disabled TINYINT(1) NOT NULL,
            precio LONGTEXT NOT NULL,
            INDEX IDX_CA3B40EC6C6EF28 (instituto_id),
            PRIMARY KEY(id)) $charset");

        // Sin tipo_pago, monto_fijo_mensual ni porcentaje_curso: los agrega Version20260312190829.
        $this->addSql("CREATE TABLE IF NOT EXISTS profesor (
            id INT AUTO_INCREMENT NOT NULL,
            instituto_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            nombre LONGTEXT NOT NULL,
            apellido LONGTEXT NOT NULL,
            dni VARCHAR(8) NOT NULL,
            email VARCHAR(191) NOT NULL,
            precio_hora LONGTEXT DEFAULT NULL,
            viatico LONGTEXT DEFAULT NULL,
            tel LONGTEXT DEFAULT NULL,
            UNIQUE INDEX UNIQ_5B7406D97F8F253B (dni),
            UNIQUE INDEX UNIQ_5B7406D9E7927C74 (email),
            INDEX IDX_5B7406D96C6EF28 (instituto_id),
            UNIQUE INDEX UNIQ_5B7406D9A76ED395 (user_id),
            PRIMARY KEY(id)) $charset");

        // Sin configuracion_id: lo agrega Version20250511124215 (junto con su foreign key).
        $this->addSql("CREATE TABLE IF NOT EXISTS vencimiento (
            id INT AUTO_INCREMENT NOT NULL,
            instituto_id INT NOT NULL,
            dia_vencimiento INT NOT NULL,
            porcentaje_interes DOUBLE PRECISION NOT NULL,
            orden INT NOT NULL,
            INDEX IDX_66923AA86C6EF28 (instituto_id),
            PRIMARY KEY(id)) $charset");

        // Solo las columnas originales. El snapshot del período (nombre_curso,
        // precio_mensual, fecha_inicio, fecha_fin) lo agrega Version20260313133945;
        // comenzar_deuda_proximo_mes, Version20260313154154; modo_generacion_deuda,
        // Version20260313160546; motivo_baja, Version20260427175705.
        $this->addSql("CREATE TABLE IF NOT EXISTS alumno_curso_historico (
            id INT AUTO_INCREMENT NOT NULL,
            alumno_id INT NOT NULL,
            curso_id INT NOT NULL,
            fecha_alta DATE NOT NULL,
            fecha_baja DATE DEFAULT NULL,
            activo TINYINT(1) NOT NULL,
            INDEX IDX_484C673EFC28E5EE (alumno_id),
            INDEX IDX_484C673E87CB4A1F (curso_id),
            PRIMARY KEY(id)) $charset");

        // Sin es_cuota_inscripcion_anual: lo agrega Version20260505144746.
        // fecha_pago, pagado y pago_id no se incluyen: Version20260205154641 los elimina
        // con guarda de existencia, así que su ausencia acá es inofensiva.
        $this->addSql("CREATE TABLE IF NOT EXISTS deuda_alumno (
            id INT AUTO_INCREMENT NOT NULL,
            alumno_id INT NOT NULL,
            curso_id INT DEFAULT NULL,
            curso_historico_id INT DEFAULT NULL,
            instituto_id INT NOT NULL,
            mes INT NOT NULL,
            ano INT NOT NULL,
            fecha_creacion DATETIME NOT NULL,
            monto DOUBLE PRECISION NOT NULL,
            interes DOUBLE PRECISION DEFAULT NULL,
            INDEX IDX_CC678E3FC28E5EE (alumno_id),
            INDEX IDX_CC678E387CB4A1F (curso_id),
            INDEX IDX_CC678E38DFEC2D0 (curso_historico_id),
            INDEX IDX_CC678E36C6EF28 (instituto_id),
            PRIMARY KEY(id)) $charset");

        // monto_restante sí se incluye: Version20260205154641 lo agrega con guarda de existencia.
        $this->addSql("CREATE TABLE IF NOT EXISTS alumnos_pagos (
            id INT AUTO_INCREMENT NOT NULL,
            alumno_id INT NOT NULL,
            curso_id INT DEFAULT NULL,
            curso_historico_id INT NOT NULL,
            fecha DATETIME NOT NULL,
            mes INT NOT NULL,
            ano INT NOT NULL,
            monto NUMERIC(10, 2) NOT NULL,
            observacion LONGTEXT DEFAULT NULL,
            metodo_pago VARCHAR(20) NOT NULL,
            monto_restante NUMERIC(10, 2) DEFAULT NULL,
            INDEX IDX_FBAE77F6FC28E5EE (alumno_id),
            INDEX IDX_FBAE77F687CB4A1F (curso_id),
            INDEX IDX_FBAE77F68DFEC2D0 (curso_historico_id),
            PRIMARY KEY(id)) $charset");

        // Estas cuatro no las menciona ninguna migración, así que van con su forma final.
        $this->addSql("CREATE TABLE IF NOT EXISTS alumno_curso (
            alumno_id INT NOT NULL,
            curso_id INT NOT NULL,
            INDEX IDX_66FE498EFC28E5EE (alumno_id),
            INDEX IDX_66FE498E87CB4A1F (curso_id),
            PRIMARY KEY(alumno_id, curso_id)) $charset");

        $this->addSql("CREATE TABLE IF NOT EXISTS curso_profesor (
            curso_id INT NOT NULL,
            profesor_id INT NOT NULL,
            INDEX IDX_9A3C3FD187CB4A1F (curso_id),
            INDEX IDX_9A3C3FD1E52BD977 (profesor_id),
            PRIMARY KEY(curso_id, profesor_id)) $charset");

        $this->addSql("CREATE TABLE IF NOT EXISTS asistencia_alumnos (
            id INT AUTO_INCREMENT NOT NULL,
            alumno_id INT NOT NULL,
            curso_id INT NOT NULL,
            fecha DATE NOT NULL,
            presente TINYINT(1) NOT NULL,
            observaciones LONGTEXT DEFAULT NULL,
            INDEX IDX_6AB82F59FC28E5EE (alumno_id),
            INDEX IDX_6AB82F5987CB4A1F (curso_id),
            PRIMARY KEY(id)) $charset");

        // 'curso' es una columna INT suelta, no una foreign key (así está mapeada).
        $this->addSql("CREATE TABLE IF NOT EXISTS asistencia_profesores (
            id INT AUTO_INCREMENT NOT NULL,
            profesor_id INT DEFAULT NULL,
            fecha DATE NOT NULL,
            profesor_remplazante INT DEFAULT NULL,
            curso INT NOT NULL,
            presente TINYINT(1) NOT NULL,
            INDEX IDX_5B73A485E52BD977 (profesor_id),
            PRIMARY KEY(id)) $charset");

        // Foreign keys. Quedan fuera las que agregan migraciones posteriores:
        // alumno.user_id (Version20251117181238), vencimiento.configuracion_id
        // (Version20250511124215) y deuda_alumno.pago_id (Version20260205154641).
        $this->addForeignKeyIfMissing('user', 'FK_8D93D6496C6EF28', 'instituto_id', 'instituto');
        $this->addForeignKeyIfMissing('alumno', 'FK_1435D52D6C6EF28', 'instituto_id', 'instituto');
        $this->addForeignKeyIfMissing('curso', 'FK_CA3B40EC6C6EF28', 'instituto_id', 'instituto');
        $this->addForeignKeyIfMissing('profesor', 'FK_5B7406D96C6EF28', 'instituto_id', 'instituto');
        $this->addForeignKeyIfMissing('profesor', 'FK_5B7406D9A76ED395', 'user_id', 'user');
        $this->addForeignKeyIfMissing('vencimiento', 'FK_66923AA86C6EF28', 'instituto_id', 'instituto');
        $this->addForeignKeyIfMissing('alumno_curso_historico', 'FK_484C673EFC28E5EE', 'alumno_id', 'alumno');
        $this->addForeignKeyIfMissing('alumno_curso_historico', 'FK_484C673E87CB4A1F', 'curso_id', 'curso');
        $this->addForeignKeyIfMissing('deuda_alumno', 'FK_CC678E3FC28E5EE', 'alumno_id', 'alumno');
        $this->addForeignKeyIfMissing('deuda_alumno', 'FK_CC678E387CB4A1F', 'curso_id', 'curso');
        $this->addForeignKeyIfMissing('deuda_alumno', 'FK_CC678E38DFEC2D0', 'curso_historico_id', 'alumno_curso_historico');
        $this->addForeignKeyIfMissing('deuda_alumno', 'FK_CC678E36C6EF28', 'instituto_id', 'instituto');
        $this->addForeignKeyIfMissing('alumnos_pagos', 'FK_FBAE77F6FC28E5EE', 'alumno_id', 'alumno');
        $this->addForeignKeyIfMissing('alumnos_pagos', 'FK_FBAE77F687CB4A1F', 'curso_id', 'curso');
        $this->addForeignKeyIfMissing('alumnos_pagos', 'FK_FBAE77F68DFEC2D0', 'curso_historico_id', 'alumno_curso_historico');
        $this->addForeignKeyIfMissing('asistencia_alumnos', 'FK_6AB82F59FC28E5EE', 'alumno_id', 'alumno');
        $this->addForeignKeyIfMissing('asistencia_alumnos', 'FK_6AB82F5987CB4A1F', 'curso_id', 'curso');
        $this->addForeignKeyIfMissing('asistencia_profesores', 'FK_5B73A485E52BD977', 'profesor_id', 'profesor');
        // Las join tables borran en cascada.
        $this->addForeignKeyIfMissing('alumno_curso', 'FK_66FE498EFC28E5EE', 'alumno_id', 'alumno', 'ON DELETE CASCADE');
        $this->addForeignKeyIfMissing('alumno_curso', 'FK_66FE498E87CB4A1F', 'curso_id', 'curso', 'ON DELETE CASCADE');
        $this->addForeignKeyIfMissing('curso_profesor', 'FK_9A3C3FD187CB4A1F', 'curso_id', 'curso', 'ON DELETE CASCADE');
        $this->addForeignKeyIfMissing('curso_profesor', 'FK_9A3C3FD1E52BD977', 'profesor_id', 'profesor', 'ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Deliberadamente vacío: esta migración solo reconstruye un estado que en
        // producción es preexistente. Revertirla borraría las tablas centrales con
        // todos sus datos. Para empezar de cero, recrear la base.
        $this->addSql('SELECT 1');
    }

    /**
     * Agrega una foreign key solo si el constraint no existe, para que aplicar esta
     * migración sobre una base que ya tiene el esquema no falle.
     */
    private function addForeignKeyIfMissing(
        string $tabla,
        string $constraint,
        string $columna,
        string $tablaReferenciada,
        string $onDelete = ''
    ): void {
        $extra = $onDelete !== '' ? ' ' . $onDelete : '';
        $this->addSql("
            SET @existe = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = '$tabla'
                AND CONSTRAINT_NAME = '$constraint');
            SET @sqlstmt = IF(@existe = 0,
                'ALTER TABLE $tabla ADD CONSTRAINT $constraint FOREIGN KEY ($columna) REFERENCES $tablaReferenciada (id)$extra',
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
    }
}
