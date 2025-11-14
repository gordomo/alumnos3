<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250511124215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Usar SQL condicional para crear tabla solo si no existe
        $this->addSql("
            SET @table_exists = (SELECT COUNT(*) FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = 'dreams' AND TABLE_NAME = 'instituto_configuracion');
            SET @sql = IF(@table_exists = 0,
                'CREATE TABLE instituto_configuracion (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, descuento_efectivo NUMERIC(5, 2) DEFAULT NULL, descuento_hermanos NUMERIC(5, 2) DEFAULT NULL, deshabilitar_descuentos_en_deuda TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_59E9F1756C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
                'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Agregar constraint solo si no existe
        $this->addSql("
            SET @constraint_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                WHERE CONSTRAINT_SCHEMA = 'dreams' AND CONSTRAINT_NAME = 'FK_59E9F1756C6EF28');
            SET @sql = IF(@constraint_exists = 0,
                'ALTER TABLE instituto_configuracion ADD CONSTRAINT FK_59E9F1756C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)',
                'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Cambiar tipo de email en alumno (solo si es LONGTEXT)
        $this->addSql("
            SET @email_type = (SELECT DATA_TYPE FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = 'dreams' AND TABLE_NAME = 'alumno' AND COLUMN_NAME = 'email');
            SET @sql = IF(@email_type IN ('longtext', 'text'),
                'ALTER TABLE alumno CHANGE email email VARCHAR(191) NOT NULL',
                'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Cambiar tipo de email en profesor (solo si es LONGTEXT)
        $this->addSql("
            SET @email_type = (SELECT DATA_TYPE FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = 'dreams' AND TABLE_NAME = 'profesor' AND COLUMN_NAME = 'email');
            SET @sql = IF(@email_type IN ('longtext', 'text'),
                'ALTER TABLE profesor CHANGE email email VARCHAR(191) NOT NULL',
                'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Crear índice único en profesor.email solo si no existe
        $this->addSql("
            SET @index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
                WHERE TABLE_SCHEMA = 'dreams' AND TABLE_NAME = 'profesor' AND INDEX_NAME = 'UNIQ_5B7406D9E7927C74');
            SET @sql = IF(@index_exists = 0,
                'CREATE UNIQUE INDEX UNIQ_5B7406D9E7927C74 ON profesor (email)',
                'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Cambiar user.instituto_id a nullable solo si no lo es
        $this->addSql("
            SET @is_nullable = (SELECT IS_NULLABLE FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = 'dreams' AND TABLE_NAME = 'user' AND COLUMN_NAME = 'instituto_id');
            SET @sql = IF(@is_nullable = 'NO',
                'ALTER TABLE user CHANGE instituto_id instituto_id INT DEFAULT NULL',
                'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Agregar columna configuracion_id en vencimiento solo si no existe
        $this->addSql("
            SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = 'dreams' AND TABLE_NAME = 'vencimiento' AND COLUMN_NAME = 'configuracion_id');
            SET @sql = IF(@column_exists = 0,
                'ALTER TABLE vencimiento ADD configuracion_id INT DEFAULT NULL',
                'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Agregar constraint en vencimiento solo si no existe
        $this->addSql("
            SET @constraint_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                WHERE CONSTRAINT_SCHEMA = 'dreams' AND CONSTRAINT_NAME = 'FK_66923AA8D18A8F98');
            SET @sql = IF(@constraint_exists = 0,
                'ALTER TABLE vencimiento ADD CONSTRAINT FK_66923AA8D18A8F98 FOREIGN KEY (configuracion_id) REFERENCES instituto_configuracion (id)',
                'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Crear índice en vencimiento solo si no existe
        $this->addSql("
            SET @index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
                WHERE TABLE_SCHEMA = 'dreams' AND TABLE_NAME = 'vencimiento' AND INDEX_NAME = 'IDX_66923AA8D18A8F98');
            SET @sql = IF(@index_exists = 0,
                'CREATE INDEX IDX_66923AA8D18A8F98 ON vencimiento (configuracion_id)',
                'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE vencimiento DROP FOREIGN KEY FK_66923AA8D18A8F98');
        $this->addSql('ALTER TABLE instituto_configuracion DROP FOREIGN KEY FK_59E9F1756C6EF28');
        $this->addSql('DROP TABLE instituto_configuracion');
        $this->addSql('ALTER TABLE alumno CHANGE email email LONGTEXT NOT NULL');
        $this->addSql('DROP INDEX UNIQ_5B7406D9E7927C74 ON profesor');
        $this->addSql('ALTER TABLE profesor CHANGE email email LONGTEXT NOT NULL');
        $this->addSql('ALTER TABLE user CHANGE instituto_id instituto_id INT NOT NULL');
        $this->addSql('DROP INDEX IDX_66923AA8D18A8F98 ON vencimiento');
        $this->addSql('ALTER TABLE vencimiento DROP configuracion_id');
    }
}
