<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Base de la suscripción de los institutos.
 *
 * Tres cosas que faltaban para poder cobrar en serio: la fecha de vencimiento de cada factura
 * (sin ella no se puede avisar ni calcular atraso), los días del ciclo y los datos de cobro en la
 * configuración global, y el precio propio de cada instituto.
 *
 * Todo con default o nulo: las facturas que ya existen quedan sin vencimiento y se tratan como no
 * vencidas hasta que se emita la próxima.
 */
final class Version20260901160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suscripción: vencimiento de factura, días del ciclo, datos de cobro y precio por instituto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_invoice ADD due_date DATE DEFAULT NULL, ADD payment_method VARCHAR(20) DEFAULT NULL, ADD mp_payment_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_config ADD dia_vencimiento INT DEFAULT 10 NOT NULL, ADD dias_gracia INT DEFAULT 5 NOT NULL, ADD dias_hasta_bloqueo INT DEFAULT 15 NOT NULL, ADD dias_aviso_previo INT DEFAULT 3 NOT NULL, ADD datos_transferencia LONGTEXT DEFAULT NULL, ADD mp_access_token VARCHAR(255) DEFAULT NULL, ADD mp_public_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE instituto ADD precio_por_alumno NUMERIC(10, 2) DEFAULT NULL, ADD minimo_mensual NUMERIC(10, 2) DEFAULT NULL, ADD suscripcion_exenta TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_invoice DROP due_date, DROP payment_method, DROP mp_payment_id');
        $this->addSql('ALTER TABLE billing_config DROP dia_vencimiento, DROP dias_gracia, DROP dias_hasta_bloqueo, DROP dias_aviso_previo, DROP datos_transferencia, DROP mp_access_token, DROP mp_public_key');
        $this->addSql('ALTER TABLE instituto DROP precio_por_alumno, DROP minimo_mensual, DROP suscripcion_exenta');
    }
}
