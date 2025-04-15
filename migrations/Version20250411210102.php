<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250411210102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE instituto_configuracion (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, descuento_efectivo NUMERIC(5, 2) DEFAULT NULL, descuento_hermanos NUMERIC(5, 2) DEFAULT NULL, deshabilitar_descuentos_en_deuda TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_59E9F1756C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE instituto_configuracion ADD CONSTRAINT FK_59E9F1756C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('ALTER TABLE profesor CHANGE email email VARCHAR(191) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5B7406D9E7927C74 ON profesor (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituto_configuracion DROP FOREIGN KEY FK_59E9F1756C6EF28');
        $this->addSql('DROP TABLE instituto_configuracion');
        $this->addSql('DROP INDEX UNIQ_5B7406D9E7927C74 ON profesor');
        $this->addSql('ALTER TABLE profesor CHANGE email email LONGTEXT NOT NULL');
    }
}
