<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250306161649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea la tabla instituto y añade un registro por defecto';
    }

    public function up(Schema $schema): void
    {
        // Crea la tabla 'instituto'
        $this->addSql('CREATE TABLE instituto (id INT AUTO_INCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        // Inserta un instituto por defecto
        $this->addSql("INSERT INTO instituto (nombre) VALUES ('Dream Ingles')");
    }

    public function down(Schema $schema): void
    {
        // Elimina la tabla 'instituto'
        $this->addSql('DROP TABLE instituto');
    }
}