<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251204144216 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE descuento_promocional (id INT AUTO_INCREMENT NOT NULL, configuracion_id INT NOT NULL, nombre VARCHAR(255) NOT NULL, porcentaje NUMERIC(5, 2) NOT NULL, activo TINYINT(1) NOT NULL, INDEX IDX_B097BC61D18A8F98 (configuracion_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE descuento_promocional ADD CONSTRAINT FK_B097BC61D18A8F98 FOREIGN KEY (configuracion_id) REFERENCES instituto_configuracion (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE descuento_promocional DROP FOREIGN KEY FK_B097BC61D18A8F98');
        $this->addSql('DROP TABLE descuento_promocional');
    }
}
