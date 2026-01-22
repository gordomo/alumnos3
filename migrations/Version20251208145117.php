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
        // Verificar si las columnas ya existen antes de crearlas
        // Usamos una función SQL que verifica la existencia y solo ejecuta si no existe
        $this->addSql("
            SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'instituto_configuracion' 
                AND COLUMN_NAME = 'enviar_facturas_recibos');
            SET @sqlstmt := IF(@exist = 0, 
                'ALTER TABLE instituto_configuracion ADD enviar_facturas_recibos TINYINT(1) DEFAULT 0 NOT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'instituto_configuracion' 
                AND COLUMN_NAME = 'enviar_recordatorios_deudas');
            SET @sqlstmt := IF(@exist = 0, 
                'ALTER TABLE instituto_configuracion ADD enviar_recordatorios_deudas TINYINT(1) DEFAULT 0 NOT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'instituto_configuracion' 
                AND COLUMN_NAME = 'enviar_recordatorio_en_dia_vencimiento');
            SET @sqlstmt := IF(@exist = 0, 
                'ALTER TABLE instituto_configuracion ADD enviar_recordatorio_en_dia_vencimiento TINYINT(1) DEFAULT 0 NOT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'instituto_configuracion' 
                AND COLUMN_NAME = 'texto_personalizado_email');
            SET @sqlstmt := IF(@exist = 0, 
                'ALTER TABLE instituto_configuracion ADD texto_personalizado_email LONGTEXT DEFAULT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituto_configuracion DROP enviar_facturas_recibos, DROP enviar_recordatorios_deudas, DROP enviar_recordatorio_en_dia_vencimiento, DROP texto_personalizado_email');
    }
}
