<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plan básico/premium, habilitación de prueba IA y contadores sin preguntas ni respuestas';
    }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE instituto ADD plan VARCHAR(20) DEFAULT 'basico' NOT NULL, ADD ayuda_ia_prueba TINYINT(1) DEFAULT 0 NOT NULL");
        $this->addSql('CREATE TABLE ayuda_ia_cuota (clave VARCHAR(100) NOT NULL, usos INT NOT NULL, vence BIGINT NOT NULL, PRIMARY KEY(clave), INDEX idx_ayuda_cuota_vence (vence)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ayuda_ia_cuota');
        $this->addSql('ALTER TABLE instituto DROP plan, DROP ayuda_ia_prueba');
    }
}
