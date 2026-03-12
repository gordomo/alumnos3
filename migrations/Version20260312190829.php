<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260312190829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE profesor_pago (id INT AUTO_INCREMENT NOT NULL, profesor_id INT NOT NULL, curso_id INT DEFAULT NULL, mes INT NOT NULL, ano INT NOT NULL, monto NUMERIC(10, 2) NOT NULL, fecha_pago DATE NOT NULL, metodo_pago VARCHAR(50) NOT NULL, observacion LONGTEXT DEFAULT NULL, detalle_calculo LONGTEXT DEFAULT NULL, fecha_creacion DATETIME NOT NULL, INDEX IDX_2C329C2E52BD977 (profesor_id), INDEX IDX_2C329C287CB4A1F (curso_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE profesor_pago ADD CONSTRAINT FK_2C329C2E52BD977 FOREIGN KEY (profesor_id) REFERENCES profesor (id)');
        $this->addSql('ALTER TABLE profesor_pago ADD CONSTRAINT FK_2C329C287CB4A1F FOREIGN KEY (curso_id) REFERENCES curso (id)');
        $this->addSql('ALTER TABLE profesor ADD tipo_pago VARCHAR(50) DEFAULT NULL, ADD monto_fijo_mensual NUMERIC(10, 2) DEFAULT NULL, ADD porcentaje_curso NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE profesor_pago DROP FOREIGN KEY FK_2C329C2E52BD977');
        $this->addSql('ALTER TABLE profesor_pago DROP FOREIGN KEY FK_2C329C287CB4A1F');
        $this->addSql('DROP TABLE profesor_pago');
        $this->addSql('ALTER TABLE profesor DROP tipo_pago, DROP monto_fijo_mensual, DROP porcentaje_curso');
    }
}
