<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220416132229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alumno (id INT AUTO_INCREMENT NOT NULL, telefono_fijo LONGTEXT DEFAULT NULL, nombre LONGTEXT NOT NULL, apellido LONGTEXT NOT NULL, f_nac DATE NOT NULL, email LONGTEXT NOT NULL, l_nac LONGTEXT DEFAULT NULL, dni LONGTEXT NOT NULL, celular LONGTEXT DEFAULT NULL, contacto_emergencia LONGTEXT DEFAULT NULL, n_tutor LONGTEXT DEFAULT NULL, t_tutor LONGTEXT DEFAULT NULL, corre_tutor LONGTEXT DEFAULT NULL, dni_tutor LONGTEXT DEFAULT NULL, escuela LONGTEXT DEFAULT NULL, extras LONGTEXT DEFAULT NULL, g_sanguineo LONGTEXT DEFAULT NULL, enfermedad LONGTEXT DEFAULT NULL, alergico LONGTEXT DEFAULT NULL, medicacion LONGTEXT DEFAULT NULL, curso LONGTEXT DEFAULT NULL, como_conociste LONGTEXT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE alumno');
    }
}
