<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Solicitudes de alta y fecha de alta del instituto.
 *
 * El alta deja de ser automática: ahora se recibe una solicitud, alguien la confirma y recién
 * ahí nace el instituto. La fecha de alta es lo que define cuál es el primer mes, que no se
 * factura.
 *
 * Los institutos que ya existen se quedan con la fecha de su primera factura, que es el dato más
 * cercano que hay. Los que nunca tuvieron factura quedan en NULL y simplemente no tienen mes de
 * cortesía.
 */
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla solicitud_instituto y columna instituto.fecha_alta';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE solicitud_instituto (
            id INT AUTO_INCREMENT NOT NULL,
            instituto_id INT DEFAULT NULL,
            nombre_instituto VARCHAR(255) NOT NULL,
            nombre_contacto VARCHAR(255) NOT NULL,
            email VARCHAR(180) NOT NULL,
            telefono VARCHAR(50) DEFAULT NULL,
            alumnos_estimados VARCHAR(20) DEFAULT NULL,
            mensaje LONGTEXT DEFAULT NULL,
            estado VARCHAR(20) NOT NULL,
            motivo_descarte LONGTEXT DEFAULT NULL,
            notas LONGTEXT DEFAULT NULL,
            fecha_solicitud DATETIME NOT NULL,
            fecha_resolucion DATETIME DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            INDEX IDX_SOLICITUD_INSTITUTO (instituto_id),
            INDEX IDX_SOLICITUD_ESTADO (estado),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE solicitud_instituto
            ADD CONSTRAINT FK_SOLICITUD_INSTITUTO FOREIGN KEY (instituto_id)
            REFERENCES instituto (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE instituto ADD fecha_alta DATE DEFAULT NULL');

        // Los institutos que ya estaban: la fecha de su primera factura.
        $this->addSql('UPDATE instituto i
            SET i.fecha_alta = (
                SELECT DATE(MIN(b.created_at)) FROM billing_invoice b WHERE b.instituto_id = i.id
            )
            WHERE i.fecha_alta IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE solicitud_instituto');
        $this->addSql('ALTER TABLE instituto DROP fecha_alta');
    }
}
