<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250410155154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alumno_curso_historico CHANGE fecha_inicio fecha_alta DATE NOT NULL, CHANGE fecha_fin fecha_baja DATE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5B7406D9E7927C74 ON profesor (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alumno_curso_historico CHANGE fecha_alta fecha_inicio DATE NOT NULL, CHANGE fecha_baja fecha_fin DATE DEFAULT NULL');
        $this->addSql('DROP INDEX UNIQ_5B7406D9E7927C74 ON profesor');
    }
}
