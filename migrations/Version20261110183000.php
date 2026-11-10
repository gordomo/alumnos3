<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110183000 extends AbstractMigration
{
    public function getDescription(): string { return 'Página pública por institución: URL única, colores, presentación y visibilidad de precios; nace como borrador'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE instituto_pagina_publica (id INT AUTO_INCREMENT NOT NULL, instituto_id INT NOT NULL, slug VARCHAR(90) NOT NULL, publicada TINYINT(1) DEFAULT 0 NOT NULL, mostrar_precios TINYINT(1) DEFAULT 1 NOT NULL, color_principal VARCHAR(7) NOT NULL, color_acento VARCHAR(7) NOT NULL, presentacion LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_pagina_instituto (instituto_id), UNIQUE INDEX uniq_pagina_slug (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB');
        $this->addSql('ALTER TABLE instituto_pagina_publica ADD CONSTRAINT fk_pagina_instituto FOREIGN KEY (instituto_id) REFERENCES instituto (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE instituto_pagina_publica'); }
}
