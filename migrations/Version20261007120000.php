<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marca de modo prueba de Mercado Pago.
 *
 * Las credenciales de prueba y las productivas tienen el mismo formato, así que hay que decir a
 * mano cuáles están cargadas. Nace apagado: si alguien ya tenía credenciales, se asume que son
 * las de verdad hasta que diga lo contrario.
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Columna billing_config.mp_modo_prueba';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_config ADD mp_modo_prueba TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_config DROP mp_modo_prueba');
    }
}
