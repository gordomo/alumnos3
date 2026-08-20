<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Se saca la opción de notificar solo al tutor.
 *
 * No tiene sentido dejar al alumn@ afuera de un aviso que es sobre él. Al instituto que tenga
 * 'tutor' cargado se le pasa a 'ambos': el tutor sigue recibiendo todo y ahora el alumn@ también.
 *
 * Solo cambia datos, no estructura: la columna sigue siendo un VARCHAR.
 */
final class Version20260820100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "notificar_a: 'tutor' pasa a 'ambos' (se saca la opción de avisar solo al tutor)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE instituto_configuracion SET notificar_a = 'ambos' WHERE notificar_a = 'tutor'");
    }

    public function down(Schema $schema): void
    {
        // No se puede volver atrás: una vez convertido a 'ambos' no queda registro de cuáles
        // eran 'tutor', y 'ambos' es un valor legítimo que otros institutos ya tenían.
        $this->throwIrreversibleMigrationException();
    }
}
