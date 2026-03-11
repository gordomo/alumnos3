<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260311194027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email_log (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, alumno_id INT DEFAULT NULL, pago_id INT DEFAULT NULL, deuda_id INT DEFAULT NULL, solicitado_por_id INT DEFAULT NULL, tipo VARCHAR(50) NOT NULL, destinatario VARCHAR(255) NOT NULL, asunto VARCHAR(255) NOT NULL, fecha_envio DATETIME NOT NULL, estado VARCHAR(20) NOT NULL, error_mensaje LONGTEXT DEFAULT NULL, es_automatico TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_6FB48836C6EF28 (instituto_id), INDEX IDX_6FB4883FC28E5EE (alumno_id), INDEX IDX_6FB488363FB8380 (pago_id), INDEX IDX_6FB4883C5CAD3D1 (deuda_id), INDEX IDX_6FB48838F99DE26 (solicitado_por_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE email_log ADD CONSTRAINT FK_6FB48836C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE email_log ADD CONSTRAINT FK_6FB4883FC28E5EE FOREIGN KEY (alumno_id) REFERENCES alumno (id)');
        $this->addSql('ALTER TABLE email_log ADD CONSTRAINT FK_6FB488363FB8380 FOREIGN KEY (pago_id) REFERENCES alumnos_pagos (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE email_log ADD CONSTRAINT FK_6FB4883C5CAD3D1 FOREIGN KEY (deuda_id) REFERENCES deuda_alumno (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE email_log ADD CONSTRAINT FK_6FB48838F99DE26 FOREIGN KEY (solicitado_por_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_log DROP FOREIGN KEY FK_6FB48836C6EF28');
        $this->addSql('ALTER TABLE email_log DROP FOREIGN KEY FK_6FB4883FC28E5EE');
        $this->addSql('ALTER TABLE email_log DROP FOREIGN KEY FK_6FB488363FB8380');
        $this->addSql('ALTER TABLE email_log DROP FOREIGN KEY FK_6FB4883C5CAD3D1');
        $this->addSql('ALTER TABLE email_log DROP FOREIGN KEY FK_6FB48838F99DE26');
        $this->addSql('DROP TABLE email_log');
    }
}
