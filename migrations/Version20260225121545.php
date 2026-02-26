<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225121545 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituto_configuracion ADD timezone VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE pago_aplicacion DROP FOREIGN KEY FK_597911AC63FB8380');
        $this->addSql('ALTER TABLE pago_aplicacion DROP FOREIGN KEY FK_597911ACC5CAD3D1');
        $this->addSql('ALTER TABLE pago_aplicacion ADD CONSTRAINT FK_597911AC63FB8380 FOREIGN KEY (pago_id) REFERENCES alumnos_pagos (id)');
        $this->addSql('ALTER TABLE pago_aplicacion ADD CONSTRAINT FK_597911ACC5CAD3D1 FOREIGN KEY (deuda_id) REFERENCES deuda_alumno (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituto_configuracion DROP timezone');
        $this->addSql('ALTER TABLE pago_aplicacion DROP FOREIGN KEY FK_597911AC63FB8380');
        $this->addSql('ALTER TABLE pago_aplicacion DROP FOREIGN KEY FK_597911ACC5CAD3D1');
        $this->addSql('ALTER TABLE pago_aplicacion ADD CONSTRAINT FK_597911AC63FB8380 FOREIGN KEY (pago_id) REFERENCES alumnos_pagos (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pago_aplicacion ADD CONSTRAINT FK_597911ACC5CAD3D1 FOREIGN KEY (deuda_id) REFERENCES deuda_alumno (id) ON DELETE CASCADE');
    }
}
