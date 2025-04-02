<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250401170239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alumno (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, telefono_fijo LONGTEXT DEFAULT NULL, nombre LONGTEXT NOT NULL, apellido LONGTEXT NOT NULL, f_nac DATE DEFAULT NULL, email LONGTEXT NOT NULL, l_nac LONGTEXT DEFAULT NULL, dni VARCHAR(10) NOT NULL, celular LONGTEXT DEFAULT NULL, contacto_emergencia LONGTEXT DEFAULT NULL, n_tutor LONGTEXT DEFAULT NULL, t_tutor LONGTEXT DEFAULT NULL, corre_tutor LONGTEXT DEFAULT NULL, dni_tutor LONGTEXT DEFAULT NULL, escuela LONGTEXT DEFAULT NULL, extras LONGTEXT DEFAULT NULL, g_sanguineo LONGTEXT DEFAULT NULL, enfermedad LONGTEXT DEFAULT NULL, alergico LONGTEXT DEFAULT NULL, medicacion LONGTEXT DEFAULT NULL, como_conociste LONGTEXT DEFAULT NULL, hermanos JSON DEFAULT NULL, activo TINYINT(1) DEFAULT NULL, UNIQUE INDEX UNIQ_1435D52D7F8F253B (dni), INDEX IDX_1435D52D6C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE alumno_curso (alumno_id INT NOT NULL, curso_id INT NOT NULL, INDEX IDX_66FE498EFC28E5EE (alumno_id), INDEX IDX_66FE498E87CB4A1F (curso_id), PRIMARY KEY(alumno_id, curso_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE alumno_curso_historico (id INT AUTO_INCREMENT NOT NULL, alumno_id INT NOT NULL, curso_id INT NOT NULL, fecha_inicio DATE NOT NULL, fecha_fin DATE DEFAULT NULL, meses_adeudados JSON NOT NULL, meses_pagados JSON NOT NULL, precio_mensual DOUBLE PRECISION NOT NULL, INDEX IDX_484C673EFC28E5EE (alumno_id), INDEX IDX_484C673E87CB4A1F (curso_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE alumnos_pagos (id INT AUTO_INCREMENT NOT NULL, alumno_id INT NOT NULL, curso_id INT DEFAULT NULL, curso_historico_id INT NOT NULL, fecha DATETIME NOT NULL, mes INT NOT NULL, ano INT NOT NULL, monto NUMERIC(10, 2) NOT NULL, observacion LONGTEXT DEFAULT NULL, metodo_pago VARCHAR(20) NOT NULL, INDEX IDX_FBAE77F6FC28E5EE (alumno_id), INDEX IDX_FBAE77F687CB4A1F (curso_id), INDEX IDX_FBAE77F68DFEC2D0 (curso_historico_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE asistencia_profesores (id INT AUTO_INCREMENT NOT NULL, profesor_id INT DEFAULT NULL, fecha DATE NOT NULL, profesor_remplazante INT DEFAULT NULL, curso INT NOT NULL, presente TINYINT(1) NOT NULL, INDEX IDX_5B73A485E52BD977 (profesor_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE curso (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, nombre LONGTEXT NOT NULL, dias JSON NOT NULL, horario_inicio TIME NOT NULL, horario_fin TIME NOT NULL, fecha_inicio DATE NOT NULL, fecha_fin DATE NOT NULL, duracion DOUBLE PRECISION NOT NULL, disabled TINYINT(1) NOT NULL, precio LONGTEXT NOT NULL, INDEX IDX_CA3B40EC6C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE instituto (id INT AUTO_INCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, logo VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, dir VARCHAR(255) DEFAULT NULL, tel INT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE profesor (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, nombre LONGTEXT NOT NULL, apellido LONGTEXT NOT NULL, dni LONGTEXT NOT NULL, email LONGTEXT NOT NULL, precio_hora LONGTEXT DEFAULT NULL, viatico LONGTEXT DEFAULT NULL, tel LONGTEXT DEFAULT NULL, INDEX IDX_5B7406D96C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE profesor_curso (profesor_id INT NOT NULL, curso_id INT NOT NULL, INDEX IDX_7EC6E2ADE52BD977 (profesor_id), INDEX IDX_7EC6E2AD87CB4A1F (curso_id), PRIMARY KEY(profesor_id, curso_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, reset_token VARCHAR(100) DEFAULT NULL, reset_token_expires_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), INDEX IDX_8D93D6496C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE vencimiento (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, dia_vencimiento INT NOT NULL, porcentaje_interes DOUBLE PRECISION NOT NULL, orden INT NOT NULL, INDEX IDX_66923AA86C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE alumno ADD CONSTRAINT FK_1435D52D6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE alumno_curso ADD CONSTRAINT FK_66FE498EFC28E5EE FOREIGN KEY (alumno_id) REFERENCES alumno (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alumno_curso ADD CONSTRAINT FK_66FE498E87CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alumno_curso_historico ADD CONSTRAINT FK_484C673EFC28E5EE FOREIGN KEY (alumno_id) REFERENCES alumno (id)');
        $this->addSql('ALTER TABLE alumno_curso_historico ADD CONSTRAINT FK_484C673E87CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)');
        $this->addSql('ALTER TABLE alumnos_pagos ADD CONSTRAINT FK_FBAE77F6FC28E5EE FOREIGN KEY (alumno_id) REFERENCES alumno (id)');
        $this->addSql('ALTER TABLE alumnos_pagos ADD CONSTRAINT FK_FBAE77F687CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)');
        $this->addSql('ALTER TABLE alumnos_pagos ADD CONSTRAINT FK_FBAE77F68DFEC2D0 FOREIGN KEY (curso_historico_id) REFERENCES alumno_curso_historico (id)');
        $this->addSql('ALTER TABLE asistencia_profesores ADD CONSTRAINT FK_5B73A485E52BD977 FOREIGN KEY (profesor_id) REFERENCES profesor (id)');
        $this->addSql('ALTER TABLE curso ADD CONSTRAINT FK_CA3B40EC6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE profesor ADD CONSTRAINT FK_5B7406D96C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE profesor_curso ADD CONSTRAINT FK_7EC6E2ADE52BD977 FOREIGN KEY (profesor_id) REFERENCES profesor (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE profesor_curso ADD CONSTRAINT FK_7EC6E2AD87CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D6496C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE vencimiento ADD CONSTRAINT FK_66923AA86C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alumno DROP FOREIGN KEY FK_1435D52D6C6EF28');
        $this->addSql('ALTER TABLE alumno_curso DROP FOREIGN KEY FK_66FE498EFC28E5EE');
        $this->addSql('ALTER TABLE alumno_curso DROP FOREIGN KEY FK_66FE498E87CB4A1F');
        $this->addSql('ALTER TABLE alumno_curso_historico DROP FOREIGN KEY FK_484C673EFC28E5EE');
        $this->addSql('ALTER TABLE alumno_curso_historico DROP FOREIGN KEY FK_484C673E87CB4A1F');
        $this->addSql('ALTER TABLE alumnos_pagos DROP FOREIGN KEY FK_FBAE77F6FC28E5EE');
        $this->addSql('ALTER TABLE alumnos_pagos DROP FOREIGN KEY FK_FBAE77F687CB4A1F');
        $this->addSql('ALTER TABLE alumnos_pagos DROP FOREIGN KEY FK_FBAE77F68DFEC2D0');
        $this->addSql('ALTER TABLE asistencia_profesores DROP FOREIGN KEY FK_5B73A485E52BD977');
        $this->addSql('ALTER TABLE curso DROP FOREIGN KEY FK_CA3B40EC6C6EF28');
        $this->addSql('ALTER TABLE profesor DROP FOREIGN KEY FK_5B7406D96C6EF28');
        $this->addSql('ALTER TABLE profesor_curso DROP FOREIGN KEY FK_7EC6E2ADE52BD977');
        $this->addSql('ALTER TABLE profesor_curso DROP FOREIGN KEY FK_7EC6E2AD87CB4A1F');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D6496C6EF28');
        $this->addSql('ALTER TABLE vencimiento DROP FOREIGN KEY FK_66923AA86C6EF28');
        $this->addSql('DROP TABLE alumno');
        $this->addSql('DROP TABLE alumno_curso');
        $this->addSql('DROP TABLE alumno_curso_historico');
        $this->addSql('DROP TABLE alumnos_pagos');
        $this->addSql('DROP TABLE asistencia_profesores');
        $this->addSql('DROP TABLE curso');
        $this->addSql('DROP TABLE instituto');
        $this->addSql('DROP TABLE profesor');
        $this->addSql('DROP TABLE profesor_curso');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE vencimiento');
    }
}
