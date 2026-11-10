<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261110220000 extends AbstractMigration
{
    public function getDescription(): string { return 'Historial de respuestas por email a consultas y preinscripciones'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE respuesta_solicitud_publica (id INT AUTO_INCREMENT NOT NULL, solicitud_id INT NOT NULL, autor_id INT DEFAULT NULL, destinatario VARCHAR(180) NOT NULL, asunto VARCHAR(180) NOT NULL, mensaje LONGTEXT NOT NULL, estado VARCHAR(20) NOT NULL, fecha DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', clave_envio VARCHAR(64) NOT NULL, UNIQUE INDEX uniq_respuesta_clave_envio (clave_envio), INDEX idx_respuesta_solicitud_fecha (solicitud_id, fecha), INDEX idx_respuesta_autor (autor_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB');
        $this->addSql('ALTER TABLE respuesta_solicitud_publica ADD CONSTRAINT fk_respuesta_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitud_pagina_publica(id) ON DELETE CASCADE, ADD CONSTRAINT fk_respuesta_autor FOREIGN KEY (autor_id) REFERENCES user(id) ON DELETE SET NULL');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE respuesta_solicitud_publica'); }
}
