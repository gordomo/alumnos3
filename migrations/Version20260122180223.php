<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260122180223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Cambiar DNI solo si la columna no tiene el tipo correcto (VARCHAR(8))
        $this->addSql("
            SET @current_type = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'alumno' 
                AND COLUMN_NAME = 'dni');
            SET @sqlstmt = IF(@current_type != 'varchar(8)', 
                'ALTER TABLE alumno CHANGE dni dni VARCHAR(8) NOT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @current_type = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'profesor' 
                AND COLUMN_NAME = 'dni');
            SET @sqlstmt = IF(@current_type != 'varchar(8)', 
                'ALTER TABLE profesor CHANGE dni dni VARCHAR(8) NOT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
    }

    public function down(Schema $schema): void
    {
        // Revertir DNI a tipos anteriores (si es necesario)
        $this->addSql("
            SET @current_type = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'alumno' 
                AND COLUMN_NAME = 'dni');
            SET @sqlstmt = IF(@current_type = 'varchar(8)', 
                'ALTER TABLE alumno CHANGE dni dni VARCHAR(10) NOT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @current_type = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'profesor' 
                AND COLUMN_NAME = 'dni');
            SET @sqlstmt = IF(@current_type = 'varchar(8)', 
                'ALTER TABLE profesor CHANGE dni dni LONGTEXT NOT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
    }
}
