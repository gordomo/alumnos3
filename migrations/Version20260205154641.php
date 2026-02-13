<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260205154641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refactorización del sistema de pagos: nueva estructura con PagoAplicacion para soportar pagos parciales y adelantados';
    }

    public function up(Schema $schema): void
    {
        // Primero eliminar foreign key de deuda_alumno que referencia a alumnos_pagos
        // para poder eliminar los datos sin problemas de integridad referencial
        $this->addSql("
            SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND CONSTRAINT_NAME = 'FK_CC678E363FB8380');
            SET @sqlstmt = IF(@fk_exists > 0, 
                'ALTER TABLE deuda_alumno DROP FOREIGN KEY FK_CC678E363FB8380', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Eliminar datos antiguos de pagos y deudas (según solicitud del usuario)
        $this->addSql("
            SET @table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'pago_aplicacion');
            SET @sqlstmt = IF(@table_exists > 0, 
                'DELETE FROM pago_aplicacion WHERE 1=1', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql('DELETE FROM alumnos_pagos WHERE 1=1');
        $this->addSql('DELETE FROM deuda_alumno WHERE 1=1');

        // Crear tabla pago_aplicacion solo si no existe
        $this->addSql("
            SET @table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'pago_aplicacion');
            SET @sqlstmt = IF(@table_exists = 0, 
                'CREATE TABLE pago_aplicacion (id INT AUTO_INCREMENT NOT NULL, pago_id INT NOT NULL, deuda_id INT NOT NULL, monto_aplicado NUMERIC(10, 2) NOT NULL, fecha_aplicacion DATETIME NOT NULL, INDEX IDX_597911AC63FB8380 (pago_id), INDEX IDX_597911ACC5CAD3D1 (deuda_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Agregar foreign keys con CASCADE solo si no existen
        $this->addSql("
            SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'pago_aplicacion' 
                AND CONSTRAINT_NAME = 'FK_597911AC63FB8380');
            SET @sqlstmt = IF(@fk_exists = 0, 
                'ALTER TABLE pago_aplicacion ADD CONSTRAINT FK_597911AC63FB8380 FOREIGN KEY (pago_id) REFERENCES alumnos_pagos (id) ON DELETE CASCADE', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'pago_aplicacion' 
                AND CONSTRAINT_NAME = 'FK_597911ACC5CAD3D1');
            SET @sqlstmt = IF(@fk_exists = 0, 
                'ALTER TABLE pago_aplicacion ADD CONSTRAINT FK_597911ACC5CAD3D1 FOREIGN KEY (deuda_id) REFERENCES deuda_alumno (id) ON DELETE CASCADE', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Agregar monto_restante a alumnos_pagos
        $this->addSql("
            SET @exist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'alumnos_pagos' 
                AND COLUMN_NAME = 'monto_restante');
            SET @sqlstmt = IF(@exist = 0, 
                'ALTER TABLE alumnos_pagos ADD monto_restante NUMERIC(10, 2) DEFAULT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Eliminar campos obsoletos de deuda_alumno
        // (La foreign key ya fue eliminada al inicio, solo falta el índice y las columnas)
        // Eliminar índice si existe
        $this->addSql("
            SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND INDEX_NAME = 'IDX_CC678E363FB8380');
            SET @sqlstmt = IF(@idx_exists > 0, 
                'ALTER TABLE deuda_alumno DROP INDEX IDX_CC678E363FB8380', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Eliminar columnas si existen
        $this->addSql("
            SET @exist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND COLUMN_NAME = 'pago_id');
            SET @sqlstmt = IF(@exist > 0, 
                'ALTER TABLE deuda_alumno DROP COLUMN pago_id', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @exist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND COLUMN_NAME = 'pagado');
            SET @sqlstmt = IF(@exist > 0, 
                'ALTER TABLE deuda_alumno DROP COLUMN pagado', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @exist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND COLUMN_NAME = 'fecha_pago');
            SET @sqlstmt = IF(@exist > 0, 
                'ALTER TABLE deuda_alumno DROP COLUMN fecha_pago', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Cambiar DNI a VARCHAR(8) solo si no está ya en ese formato
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
        
        // Crear índice único en profesor.dni solo si no existe
        $this->addSql("
            SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'profesor' 
                AND INDEX_NAME = 'UNIQ_5B7406D97F8F253B');
            SET @sqlstmt = IF(@idx_exists = 0, 
                'CREATE UNIQUE INDEX UNIQ_5B7406D97F8F253B ON profesor (dni)', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
    }

    public function down(Schema $schema): void
    {
        // Eliminar foreign keys de pago_aplicacion si existen
        $this->addSql("
            SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'pago_aplicacion' 
                AND CONSTRAINT_NAME = 'FK_597911AC63FB8380');
            SET @sqlstmt = IF(@fk_exists > 0, 
                'ALTER TABLE pago_aplicacion DROP FOREIGN KEY FK_597911AC63FB8380', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'pago_aplicacion' 
                AND CONSTRAINT_NAME = 'FK_597911ACC5CAD3D1');
            SET @sqlstmt = IF(@fk_exists > 0, 
                'ALTER TABLE pago_aplicacion DROP FOREIGN KEY FK_597911ACC5CAD3D1', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Eliminar tabla pago_aplicacion si existe
        $this->addSql("
            SET @table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'pago_aplicacion');
            SET @sqlstmt = IF(@table_exists > 0, 
                'DROP TABLE pago_aplicacion', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Eliminar monto_restante de alumnos_pagos
        $this->addSql("
            SET @exist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'alumnos_pagos' 
                AND COLUMN_NAME = 'monto_restante');
            SET @sqlstmt = IF(@exist > 0, 
                'ALTER TABLE alumnos_pagos DROP COLUMN monto_restante', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Restaurar campos obsoletos en deuda_alumno
        $this->addSql("
            SET @exist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND COLUMN_NAME = 'pago_id');
            SET @sqlstmt = IF(@exist = 0, 
                'ALTER TABLE deuda_alumno ADD pago_id INT DEFAULT NULL, ADD pagado TINYINT(1) DEFAULT 0 NOT NULL, ADD fecha_pago DATETIME DEFAULT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Restaurar foreign key e índice si existe pago_id
        $this->addSql("
            SET @exist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND COLUMN_NAME = 'pago_id');
            SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND CONSTRAINT_NAME = 'FK_CC678E363FB8380');
            SET @sqlstmt = IF(@exist > 0 AND @fk_exists = 0, 
                'ALTER TABLE deuda_alumno ADD CONSTRAINT FK_CC678E363FB8380 FOREIGN KEY (pago_id) REFERENCES alumnos_pagos (id)', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        $this->addSql("
            SET @exist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND COLUMN_NAME = 'pago_id');
            SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'deuda_alumno' 
                AND INDEX_NAME = 'IDX_CC678E363FB8380');
            SET @sqlstmt = IF(@exist > 0 AND @idx_exists = 0, 
                'CREATE INDEX IDX_CC678E363FB8380 ON deuda_alumno (pago_id)', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Eliminar índice único de profesor.dni si existe
        $this->addSql("
            SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'profesor' 
                AND INDEX_NAME = 'UNIQ_5B7406D97F8F253B');
            SET @sqlstmt = IF(@idx_exists > 0, 
                'DROP INDEX UNIQ_5B7406D97F8F253B ON profesor', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Revertir DNI (no revertimos a VARCHAR(30) ya que el formato correcto es VARCHAR(8))
        // Solo revertimos si realmente necesitamos volver atrás
    }
}
