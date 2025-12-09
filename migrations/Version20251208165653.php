<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251208165653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE token_action (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, cost INT NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_46B4239077153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE token_balance (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, balance INT NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_42FB067D6C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE token_transaction (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, user_id INT DEFAULT NULL, action VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, amount INT NOT NULL, balance_after INT NOT NULL, created_at DATETIME NOT NULL, entity_type VARCHAR(50) DEFAULT NULL, entity_id INT DEFAULT NULL, INDEX IDX_5E06574BA76ED395 (user_id), INDEX idx_created_at (created_at), INDEX idx_instituto (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE token_balance ADD CONSTRAINT FK_42FB067D6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE token_transaction ADD CONSTRAINT FK_5E06574B6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE token_transaction ADD CONSTRAINT FK_5E06574BA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE token_balance DROP FOREIGN KEY FK_42FB067D6C6EF28');
        $this->addSql('ALTER TABLE token_transaction DROP FOREIGN KEY FK_5E06574B6C6EF28');
        $this->addSql('ALTER TABLE token_transaction DROP FOREIGN KEY FK_5E06574BA76ED395');
        $this->addSql('DROP TABLE token_action');
        $this->addSql('DROP TABLE token_balance');
        $this->addSql('DROP TABLE token_transaction');
    }
}
