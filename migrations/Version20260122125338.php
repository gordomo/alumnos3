<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260122125338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Agregar columnas solo si no existen
        $this->addSql("
            SET @exist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'billing_invoice' 
                AND COLUMN_NAME = 'approved_by_id');
            SET @sqlstmt = IF(@exist = 0, 
                'ALTER TABLE billing_invoice ADD approved_by_id INT DEFAULT NULL, ADD payment_requested_at DATETIME DEFAULT NULL, ADD payment_proof_path VARCHAR(255) DEFAULT NULL, ADD rejection_reason LONGTEXT DEFAULT NULL, ADD rejected_at DATETIME DEFAULT NULL, ADD approved_at DATETIME DEFAULT NULL', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Agregar foreign key solo si no existe
        $this->addSql("
            SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'billing_invoice' 
                AND CONSTRAINT_NAME = 'FK_FB4B9C932D234F6A');
            SET @sqlstmt = IF(@fk_exists = 0, 
                'ALTER TABLE billing_invoice ADD CONSTRAINT FK_FB4B9C932D234F6A FOREIGN KEY (approved_by_id) REFERENCES user (id)', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
        
        // Crear índice solo si no existe
        $this->addSql("
            SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'billing_invoice' 
                AND INDEX_NAME = 'IDX_FB4B9C932D234F6A');
            SET @sqlstmt = IF(@idx_exists = 0, 
                'CREATE INDEX IDX_FB4B9C932D234F6A ON billing_invoice (approved_by_id)', 
                'SELECT 1');
            PREPARE stmt FROM @sqlstmt;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE billing_invoice DROP FOREIGN KEY FK_FB4B9C932D234F6A');
        $this->addSql('DROP INDEX IDX_FB4B9C932D234F6A ON billing_invoice');
        $this->addSql('ALTER TABLE billing_invoice DROP approved_by_id, DROP payment_requested_at, DROP payment_proof_path, DROP rejection_reason, DROP rejected_at, DROP approved_at');
    }
}
