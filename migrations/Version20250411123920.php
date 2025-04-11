<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250411123920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE deuda_alumno (
          id INT AUTO_INCREMENT NOT NULL,
          alumno_id INT NOT NULL,
          curso_id INT NOT NULL,
          curso_historico_id INT DEFAULT NULL,
          pago_id INT DEFAULT NULL,
          instituto_id INT NOT NULL,
          mes INT NOT NULL,
          ano INT NOT NULL,
          pagado TINYINT(1) NOT NULL,
          fecha_creacion DATETIME NOT NULL,
          fecha_pago DATETIME DEFAULT NULL,
          monto DOUBLE PRECISION NOT NULL,
          interes DOUBLE PRECISION DEFAULT NULL,
          INDEX IDX_CC678E3FC28E5EE (alumno_id),
          INDEX IDX_CC678E387CB4A1F (curso_id),
          INDEX IDX_CC678E38DFEC2D0 (curso_historico_id),
          INDEX IDX_CC678E363FB8380 (pago_id),
          INDEX IDX_CC678E36C6EF28 (instituto_id),
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE
          deuda_alumno
        ADD
          CONSTRAINT FK_CC678E3FC28E5EE FOREIGN KEY (alumno_id) REFERENCES alumno (id)');
        $this->addSql('ALTER TABLE
          deuda_alumno
        ADD
          CONSTRAINT FK_CC678E387CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)');
        $this->addSql('ALTER TABLE
          deuda_alumno
        ADD
          CONSTRAINT FK_CC678E38DFEC2D0 FOREIGN KEY (curso_historico_id) REFERENCES alumno_curso_historico (id)');
        $this->addSql('ALTER TABLE
          deuda_alumno
        ADD
          CONSTRAINT FK_CC678E363FB8380 FOREIGN KEY (pago_id) REFERENCES alumnos_pagos (id)');
        $this->addSql('ALTER TABLE
          deuda_alumno
        ADD
          CONSTRAINT FK_CC678E36C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5B7406D9E7927C74 ON profesor (email(191))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE deuda_alumno DROP FOREIGN KEY FK_CC678E3FC28E5EE');
        $this->addSql('ALTER TABLE deuda_alumno DROP FOREIGN KEY FK_CC678E387CB4A1F');
        $this->addSql('ALTER TABLE deuda_alumno DROP FOREIGN KEY FK_CC678E38DFEC2D0');
        $this->addSql('ALTER TABLE deuda_alumno DROP FOREIGN KEY FK_CC678E363FB8380');
        $this->addSql('ALTER TABLE deuda_alumno DROP FOREIGN KEY FK_CC678E36C6EF28');
        $this->addSql('DROP TABLE deuda_alumno');
        $this->addSql('DROP INDEX UNIQ_5B7406D9E7927C74 ON profesor');
    }
}
