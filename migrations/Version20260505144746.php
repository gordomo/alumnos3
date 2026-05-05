<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260505144746 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE deuda_alumno ADD es_cuota_inscripcion_anual TINYINT(1) DEFAULT 0 NOT NULL, CHANGE curso_id curso_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE instituto_configuracion ADD cobrar_cuota_inscripcion_anual TINYINT(1) DEFAULT 0 NOT NULL, ADD monto_cuota_inscripcion_anual NUMERIC(10, 2) DEFAULT NULL, ADD mes_cobro_cuota_inscripcion_anual INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE deuda_alumno DROP es_cuota_inscripcion_anual, CHANGE curso_id curso_id INT NOT NULL');
        $this->addSql('ALTER TABLE instituto_configuracion DROP cobrar_cuota_inscripcion_anual, DROP monto_cuota_inscripcion_anual, DROP mes_cobro_cuota_inscripcion_anual');
    }
}
