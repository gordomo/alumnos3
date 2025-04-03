<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250403161453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE asistencia_alumnos (id INT AUTO_INCREMENT NOT NULL, alumno_id INT NOT NULL, curso_id INT NOT NULL, fecha DATE NOT NULL, presente TINYINT(1) NOT NULL, observaciones LONGTEXT DEFAULT NULL, INDEX IDX_6AB82F59FC28E5EE (alumno_id), INDEX IDX_6AB82F5987CB4A1F (curso_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE curso_profesor (curso_id INT NOT NULL, profesor_id INT NOT NULL, INDEX IDX_9A3C3FD187CB4A1F (curso_id), INDEX IDX_9A3C3FD1E52BD977 (profesor_id), PRIMARY KEY(curso_id, profesor_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE asistencia_alumnos ADD CONSTRAINT FK_6AB82F59FC28E5EE FOREIGN KEY (alumno_id) REFERENCES alumno (id)');
        $this->addSql('ALTER TABLE asistencia_alumnos ADD CONSTRAINT FK_6AB82F5987CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)');
        $this->addSql('ALTER TABLE curso_profesor ADD CONSTRAINT FK_9A3C3FD187CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curso_profesor ADD CONSTRAINT FK_9A3C3FD1E52BD977 FOREIGN KEY (profesor_id) REFERENCES profesor (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE profesor_curso DROP FOREIGN KEY FK_7EC6E2AD87CB4A1F');
        $this->addSql('ALTER TABLE profesor_curso DROP FOREIGN KEY FK_7EC6E2ADE52BD977');
        $this->addSql('DROP TABLE profesor_curso');
        $this->addSql('ALTER TABLE alumno_curso_historico CHANGE activo activo TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE profesor_curso (profesor_id INT NOT NULL, curso_id INT NOT NULL, INDEX IDX_7EC6E2ADE52BD977 (profesor_id), INDEX IDX_7EC6E2AD87CB4A1F (curso_id), PRIMARY KEY(profesor_id, curso_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE profesor_curso ADD CONSTRAINT FK_7EC6E2AD87CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE profesor_curso ADD CONSTRAINT FK_7EC6E2ADE52BD977 FOREIGN KEY (profesor_id) REFERENCES profesor (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE asistencia_alumnos DROP FOREIGN KEY FK_6AB82F59FC28E5EE');
        $this->addSql('ALTER TABLE asistencia_alumnos DROP FOREIGN KEY FK_6AB82F5987CB4A1F');
        $this->addSql('ALTER TABLE curso_profesor DROP FOREIGN KEY FK_9A3C3FD187CB4A1F');
        $this->addSql('ALTER TABLE curso_profesor DROP FOREIGN KEY FK_9A3C3FD1E52BD977');
        $this->addSql('DROP TABLE asistencia_alumnos');
        $this->addSql('DROP TABLE curso_profesor');
        $this->addSql('ALTER TABLE alumno_curso_historico CHANGE activo activo TINYINT(1) DEFAULT 1 NOT NULL');
    }
}
