<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261110230000 extends AbstractMigration
{
    public function getDescription(): string { return 'Correo entrante de conversaciones: identificadores únicos, mensajes y estado de lectura por instituto'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE solicitud_pagina_publica ADD correo_token VARCHAR(32) DEFAULT NULL, ADD correos_nuevos TINYINT(1) DEFAULT 0 NOT NULL, ADD UNIQUE INDEX uniq_solicitud_correo_token (correo_token), ADD INDEX idx_solicitud_correo_nuevo (instituto_id, correos_nuevos)');
        $this->addSql('ALTER TABLE respuesta_solicitud_publica ADD correo_message_id VARCHAR(180) DEFAULT NULL, ADD UNIQUE INDEX uniq_respuesta_message_id (correo_message_id)');
        $this->addSql('CREATE TABLE mensaje_entrante_solicitud (id INT AUTO_INCREMENT NOT NULL, solicitud_id INT NOT NULL, remitente VARCHAR(180) NOT NULL, asunto VARCHAR(255) NOT NULL, mensaje LONGTEXT NOT NULL, fecha DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', huella VARCHAR(64) NOT NULL, archivo_original VARCHAR(68) NOT NULL, adjuntos JSON NOT NULL, UNIQUE INDEX uniq_mensaje_entrante_huella (huella), INDEX idx_entrante_solicitud_fecha (solicitud_id,fecha), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB');
        $this->addSql('ALTER TABLE mensaje_entrante_solicitud ADD CONSTRAINT fk_entrante_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitud_pagina_publica(id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mensaje_entrante_solicitud');
        $this->addSql('ALTER TABLE respuesta_solicitud_publica DROP INDEX uniq_respuesta_message_id, DROP correo_message_id');
        $this->addSql('ALTER TABLE solicitud_pagina_publica DROP INDEX uniq_solicitud_correo_token, DROP INDEX idx_solicitud_correo_nuevo, DROP correo_token, DROP correos_nuevos');
    }
}
