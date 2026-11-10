<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261110200000 extends AbstractMigration
{
    public function getDescription(): string { return 'Descripción pública opcional de los cursos para el catálogo de la landing'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE curso ADD descripcion_publica LONGTEXT DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE curso DROP descripcion_publica'); }
}
