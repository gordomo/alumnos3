<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251215181352 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE billing_invoice (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, period_year INT NOT NULL, period_month INT NOT NULL, active_students_count INT NOT NULL, price_per_student NUMERIC(10, 2) NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, status VARCHAR(20) NOT NULL, paid_at DATETIME DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_FB4B9C936C6EF28 (instituto_id), INDEX idx_billing_period (instituto_id, period_year, period_month), INDEX idx_billing_status (status), INDEX idx_created_at (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE billing_invoice ADD CONSTRAINT FK_FB4B9C936C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE token_balance DROP FOREIGN KEY FK_42FB067D6C6EF28');
        $this->addSql('ALTER TABLE token_transaction DROP FOREIGN KEY FK_5E06574BA76ED395');
        $this->addSql('ALTER TABLE token_transaction DROP FOREIGN KEY FK_5E06574B6C6EF28');
        $this->addSql('DROP TABLE token_action');
        $this->addSql('DROP TABLE token_balance');
        $this->addSql('DROP TABLE token_transaction');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE token_action (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, cost INT NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_46B4239077153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE token_balance (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, balance INT NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_42FB067D6C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE token_transaction (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, user_id INT DEFAULT NULL, action VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, description VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, amount INT NOT NULL, balance_after INT NOT NULL, created_at DATETIME NOT NULL, entity_type VARCHAR(50) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, entity_id INT DEFAULT NULL, INDEX idx_instituto (instituto_id), INDEX IDX_5E06574BA76ED395 (user_id), INDEX idx_created_at (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE token_balance ADD CONSTRAINT FK_42FB067D6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE token_transaction ADD CONSTRAINT FK_5E06574BA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE token_transaction ADD CONSTRAINT FK_5E06574B6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE billing_invoice DROP FOREIGN KEY FK_FB4B9C936C6EF28');
        $this->addSql('DROP TABLE billing_invoice');
    }
}
