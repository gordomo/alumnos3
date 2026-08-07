<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colores de la libreta por instituto.
 *
 * El logo ya existia en instituto.logo. Con estos dos colores cada instituto tiene su libreta
 * con su identidad, sin tener que subir un archivo propio.
 *
 * Nullable: sin colores cargados la libreta usa los del sistema.
 */
final class Version20260807100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Colores de la libreta por instituto";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE instituto_configuracion ADD color_primario VARCHAR(7) DEFAULT NULL, ADD color_secundario VARCHAR(7) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE instituto_configuracion DROP color_primario, DROP color_secundario");
    }
}
