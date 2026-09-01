<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Diario de clases y materiales de consulta por curso.
 *
 * Dos tablas nuevas, no toca nada existente. Son lo que el instituto pidió como "contenidos
 * dictados" y "materiales de consulta" para que los vean alumn@s y tutores.
 */
final class Version20260901120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'clase_dictada y material_curso: diario de clases y materiales por curso';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE clase_dictada (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, curso_id INT NOT NULL, cargado_por_id INT DEFAULT NULL, fecha DATE NOT NULL, tema VARCHAR(200) NOT NULL, detalle LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_9F3FD1316C6EF28 (instituto_id), INDEX IDX_9F3FD13187CB4A1F (curso_id), INDEX IDX_9F3FD131E87641F9 (cargado_por_id), UNIQUE INDEX uniq_clase_dictada_curso_fecha (curso_id, fecha), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql("CREATE TABLE material_curso (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, curso_id INT NOT NULL, cargado_por_id INT DEFAULT NULL, titulo VARCHAR(150) NOT NULL, url VARCHAR(500) NOT NULL, tipo VARCHAR(20) DEFAULT 'enlace' NOT NULL, descripcion LONGTEXT DEFAULT NULL, visible TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_9CDCECA26C6EF28 (instituto_id), INDEX IDX_9CDCECA287CB4A1F (curso_id), INDEX IDX_9CDCECA2E87641F9 (cargado_por_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE clase_dictada ADD CONSTRAINT FK_9F3FD1316C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE clase_dictada ADD CONSTRAINT FK_9F3FD13187CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE clase_dictada ADD CONSTRAINT FK_9F3FD131E87641F9 FOREIGN KEY (cargado_por_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE material_curso ADD CONSTRAINT FK_9CDCECA26C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE material_curso ADD CONSTRAINT FK_9CDCECA287CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE material_curso ADD CONSTRAINT FK_9CDCECA2E87641F9 FOREIGN KEY (cargado_por_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE clase_dictada');
        $this->addSql('DROP TABLE material_curso');
    }
}
