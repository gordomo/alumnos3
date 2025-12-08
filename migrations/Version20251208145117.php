<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251208145117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituto_configuracion ADD enviar_facturas_recibos TINYINT(1) DEFAULT 0 NOT NULL, ADD enviar_recordatorios_deudas TINYINT(1) DEFAULT 0 NOT NULL, ADD enviar_recordatorio_en_dia_vencimiento TINYINT(1) DEFAULT 0 NOT NULL, ADD texto_personalizado_email LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituto_configuracion DROP enviar_facturas_recibos, DROP enviar_recordatorios_deudas, DROP enviar_recordatorio_en_dia_vencimiento, DROP texto_personalizado_email');
    }
}
