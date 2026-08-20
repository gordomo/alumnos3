<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eventos propios de la agenda: feriados, actos, reuniones, mesas de examen.
 *
 * Tabla nueva, no toca nada existente. El resto de lo que muestra la agenda es información que
 * ya está en el sistema y no necesita tabla.
 */
final class Version20260820120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'evento_agenda: eventos que el instituto carga a mano en la agenda';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE evento_agenda (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, curso_id INT DEFAULT NULL, creado_por_id INT DEFAULT NULL, titulo VARCHAR(150) NOT NULL, descripcion LONGTEXT DEFAULT NULL, tipo VARCHAR(30) NOT NULL, fecha_inicio DATETIME NOT NULL, fecha_fin DATETIME DEFAULT NULL, todo_el_dia TINYINT(1) DEFAULT 1 NOT NULL, visibilidad VARCHAR(20) DEFAULT 'todos' NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_D544D5266C6EF28 (instituto_id), INDEX IDX_D544D52687CB4A1F (curso_id), INDEX IDX_D544D526FE35D8C4 (creado_por_id), INDEX idx_evento_agenda_rango (instituto_id, fecha_inicio), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE evento_agenda ADD CONSTRAINT FK_D544D5266C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE evento_agenda ADD CONSTRAINT FK_D544D52687CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE evento_agenda ADD CONSTRAINT FK_D544D526FE35D8C4 FOREIGN KEY (creado_por_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE evento_agenda');
    }
}
