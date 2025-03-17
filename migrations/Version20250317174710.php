<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250317174710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega campos horarioInicio y horarioFin a la tabla curso';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curso ADD horario_inicio TIME DEFAULT NULL, ADD horario_fin TIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curso DROP horario_inicio, DROP horario_fin');
    }
}
