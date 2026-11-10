<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261110210000 extends AbstractMigration
{
    public function getDescription(): string { return 'Bandeja por instituto para contactos y preinscripciones de la página pública'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE solicitud_pagina_publica (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, curso_id INT DEFAULT NULL, alumno_id INT DEFAULT NULL, tipo VARCHAR(20) NOT NULL, estado VARCHAR(20) NOT NULL, nombre VARCHAR(100) NOT NULL, apellido VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL, telefono VARCHAR(50) DEFAULT NULL, mensaje LONGTEXT DEFAULT NULL, fecha DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', fecha_resolucion DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', token_hash VARCHAR(64) NOT NULL, clave_activa VARCHAR(64) DEFAULT NULL, UNIQUE INDEX uniq_solicitud_publica_token (token_hash), UNIQUE INDEX uniq_solicitud_publica_activa (clave_activa), INDEX idx_solicitud_publica_bandeja (instituto_id, estado, fecha), INDEX idx_solicitud_publica_curso (curso_id), INDEX idx_solicitud_publica_alumno (alumno_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB');
        $this->addSql('ALTER TABLE solicitud_pagina_publica ADD CONSTRAINT fk_solicitud_publica_instituto FOREIGN KEY (instituto_id) REFERENCES instituto(id) ON DELETE CASCADE, ADD CONSTRAINT fk_solicitud_publica_curso FOREIGN KEY (curso_id) REFERENCES curso(id) ON DELETE SET NULL, ADD CONSTRAINT fk_solicitud_publica_alumno FOREIGN KEY (alumno_id) REFERENCES alumno(id) ON DELETE SET NULL');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE solicitud_pagina_publica'); }
}
