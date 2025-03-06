<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220426182519 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE horario_profesor DROP FOREIGN KEY FK_388214EC4959F1BA');
        $this->addSql('DROP TABLE horario');
        $this->addSql('DROP TABLE horario_profesor');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE horario (id INT AUTO_INCREMENT NOT NULL, curso_id INT NOT NULL, dias_horas JSON NOT NULL, UNIQUE INDEX UNIQ_E25853A387CB4A1F (curso_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE horario_profesor (horario_id INT NOT NULL, profesor_id INT NOT NULL, INDEX IDX_388214EC4959F1BA (horario_id), INDEX IDX_388214ECE52BD977 (profesor_id), PRIMARY KEY(horario_id, profesor_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE horario ADD CONSTRAINT FK_E25853A387CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)');
        $this->addSql('ALTER TABLE horario_profesor ADD CONSTRAINT FK_388214ECE52BD977 FOREIGN KEY (profesor_id) REFERENCES profesor (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE horario_profesor ADD CONSTRAINT FK_388214EC4959F1BA FOREIGN KEY (horario_id) REFERENCES horario (id) ON DELETE CASCADE');
    }
}
