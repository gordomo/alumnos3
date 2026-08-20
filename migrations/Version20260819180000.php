<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reglas de pago a profesor por curso, y la base sobre la que se calcula el porcentaje.
 *
 * No toca ninguna columna existente. Mientras no se cargue una regla, el cálculo sigue
 * usando la configuración que está en profesor, así que el comportamiento no cambia.
 */
final class Version20260819180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pago a profesor por curso (profesor_curso_pago) y base del porcentaje por instituto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE profesor_curso_pago (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, profesor_id INT NOT NULL, curso_id INT NOT NULL, modalidad VARCHAR(30) NOT NULL, precio_hora NUMERIC(10, 2) DEFAULT NULL, viatico NUMERIC(10, 2) DEFAULT NULL, porcentaje NUMERIC(5, 2) DEFAULT NULL, monto_fijo NUMERIC(10, 2) DEFAULT NULL, activo TINYINT(1) DEFAULT 1 NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_79A5433B6C6EF28 (instituto_id), INDEX IDX_79A5433BE52BD977 (profesor_id), INDEX IDX_79A5433B87CB4A1F (curso_id), UNIQUE INDEX uniq_profesor_curso_pago (profesor_id, curso_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE profesor_curso_pago ADD CONSTRAINT FK_79A5433B6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE profesor_curso_pago ADD CONSTRAINT FK_79A5433BE52BD977 FOREIGN KEY (profesor_id) REFERENCES profesor (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE profesor_curso_pago ADD CONSTRAINT FK_79A5433B87CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE CASCADE');

        $this->addSql("ALTER TABLE instituto_configuracion ADD base_porcentaje_profesor VARCHAR(20) DEFAULT 'cobrado' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE profesor_curso_pago');
        $this->addSql('ALTER TABLE instituto_configuracion DROP base_porcentaje_profesor');
    }
}
