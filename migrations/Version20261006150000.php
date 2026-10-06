<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baja lógica del instituto.
 *
 * Un instituto dado de baja deja de entrar y de facturarse, pero conserva todos sus datos. Es lo
 * que corresponde cuando un cliente se va; el borrado definitivo es otra cosa y no deja rastro.
 *
 * Todos los institutos que ya existen quedan activos.
 */
final class Version20261006150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Columnas activo, fecha_baja y motivo_baja en instituto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instituto
            ADD activo TINYINT(1) DEFAULT 1 NOT NULL,
            ADD fecha_baja DATE DEFAULT NULL,
            ADD motivo_baja LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instituto DROP activo, DROP fecha_baja, DROP motivo_baja');
    }
}
