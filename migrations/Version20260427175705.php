<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260427175705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE saldo_favor (id INT AUTO_INCREMENT NOT NULL, alumno_id INT NOT NULL, instituto_id INT NOT NULL, pago_origen_id INT DEFAULT NULL, curso_id INT DEFAULT NULL, monto NUMERIC(10, 2) NOT NULL, monto_disponible NUMERIC(10, 2) NOT NULL, tipo VARCHAR(50) NOT NULL, descripcion LONGTEXT DEFAULT NULL, fecha DATETIME NOT NULL, INDEX IDX_C548D19EFC28E5EE (alumno_id), INDEX IDX_C548D19E6C6EF28 (instituto_id), INDEX IDX_C548D19E4A137986 (pago_origen_id), INDEX IDX_C548D19E87CB4A1F (curso_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE saldo_favor_aplicacion (id INT AUTO_INCREMENT NOT NULL, saldo_favor_id INT NOT NULL, deuda_id INT NOT NULL, monto_aplicado NUMERIC(10, 2) NOT NULL, fecha_aplicacion DATETIME NOT NULL, INDEX IDX_B75BEE4271E86DF3 (saldo_favor_id), INDEX IDX_B75BEE42C5CAD3D1 (deuda_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE saldo_favor ADD CONSTRAINT FK_C548D19EFC28E5EE FOREIGN KEY (alumno_id) REFERENCES alumno (id)');
        $this->addSql('ALTER TABLE saldo_favor ADD CONSTRAINT FK_C548D19E6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE saldo_favor ADD CONSTRAINT FK_C548D19E4A137986 FOREIGN KEY (pago_origen_id) REFERENCES alumnos_pagos (id)');
        $this->addSql('ALTER TABLE saldo_favor ADD CONSTRAINT FK_C548D19E87CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)');
        $this->addSql('ALTER TABLE saldo_favor_aplicacion ADD CONSTRAINT FK_B75BEE4271E86DF3 FOREIGN KEY (saldo_favor_id) REFERENCES saldo_favor (id)');
        $this->addSql('ALTER TABLE saldo_favor_aplicacion ADD CONSTRAINT FK_B75BEE42C5CAD3D1 FOREIGN KEY (deuda_id) REFERENCES deuda_alumno (id)');
        $this->addSql('ALTER TABLE alumno_curso_historico ADD motivo_baja VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE curso ADD cerrado TINYINT(1) DEFAULT 0 NOT NULL, ADD fecha_cierre DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE instituto_configuracion ADD porcentaje_asistencia_aprobacion NUMERIC(5, 2) DEFAULT NULL, ADD requiere_pago_total_para_aprobar TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE saldo_favor DROP FOREIGN KEY FK_C548D19EFC28E5EE');
        $this->addSql('ALTER TABLE saldo_favor DROP FOREIGN KEY FK_C548D19E6C6EF28');
        $this->addSql('ALTER TABLE saldo_favor DROP FOREIGN KEY FK_C548D19E4A137986');
        $this->addSql('ALTER TABLE saldo_favor DROP FOREIGN KEY FK_C548D19E87CB4A1F');
        $this->addSql('ALTER TABLE saldo_favor_aplicacion DROP FOREIGN KEY FK_B75BEE4271E86DF3');
        $this->addSql('ALTER TABLE saldo_favor_aplicacion DROP FOREIGN KEY FK_B75BEE42C5CAD3D1');
        $this->addSql('DROP TABLE saldo_favor');
        $this->addSql('DROP TABLE saldo_favor_aplicacion');
        $this->addSql('ALTER TABLE alumno_curso_historico DROP motivo_baja');
        $this->addSql('ALTER TABLE curso DROP cerrado, DROP fecha_cierre');
        $this->addSql('ALTER TABLE instituto_configuracion DROP porcentaje_asistencia_aprobacion, DROP requiere_pago_total_para_aprobar');
    }
}
