<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260318172251 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE metodo_pago (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, nombre VARCHAR(100) NOT NULL, activo TINYINT(1) NOT NULL, orden INT NOT NULL, INDEX IDX_8A0E88686C6EF28 (instituto_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE metodo_pago ADD CONSTRAINT FK_8A0E88686C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        
        // Insertar métodos de pago por defecto para cada instituto existente
        $this->addSql('
            INSERT INTO metodo_pago (instituto_id, nombre, activo, orden)
            SELECT id, "Efectivo", 1, 1 FROM instituto
        ');
        $this->addSql('
            INSERT INTO metodo_pago (instituto_id, nombre, activo, orden)
            SELECT id, "Transferencia", 1, 2 FROM instituto
        ');
        $this->addSql('
            INSERT INTO metodo_pago (instituto_id, nombre, activo, orden)
            SELECT id, "Tarjeta de Débito", 1, 3 FROM instituto
        ');
        $this->addSql('
            INSERT INTO metodo_pago (instituto_id, nombre, activo, orden)
            SELECT id, "Tarjeta de Crédito", 1, 4 FROM instituto
        ');
        $this->addSql('
            INSERT INTO metodo_pago (instituto_id, nombre, activo, orden)
            SELECT id, "Mercado Pago", 1, 5 FROM instituto
        ');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE metodo_pago DROP FOREIGN KEY FK_8A0E88686C6EF28');
        $this->addSql('DROP TABLE metodo_pago');
    }
}
