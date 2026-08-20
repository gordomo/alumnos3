<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marca "este pago salda el total" en el pago a profesor.
 *
 * Los pagos ya registrados quedan en 0, o sea que ninguna liquidación pasada cambia de estado.
 */
final class Version20260820110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'profesor_pago.salda_total: cerrar la liquidación del mes con un pago parcial';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profesor_pago ADD salda_total TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profesor_pago DROP salda_total');
    }
}
