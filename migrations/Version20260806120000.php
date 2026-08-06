<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notificaciones: destinatarios configurables y reglas del recordatorio de deuda.
 *
 * Todo aditivo y con DEFAULT, asi que los institutos existentes no cambian de
 * comportamiento: notificar_a arranca en "alumno", que es lo unico que hacia el sistema
 * antes, y recordatorio_cada_dias en 3, que es el valor que estaba fijo en el servicio.
 */
final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Notificaciones: destinatarios y reglas del recordatorio de deuda";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE instituto_configuracion ADD notificar_a VARCHAR(20) DEFAULT 'alumno' NOT NULL, ADD recordatorio_dias_mes VARCHAR(60) DEFAULT NULL, ADD recordatorio_cada_dias INT DEFAULT 3, ADD recordatorio_min_cuotas_vencidas INT DEFAULT 1 NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE instituto_configuracion DROP notificar_a, DROP recordatorio_dias_mes, DROP recordatorio_cada_dias, DROP recordatorio_min_cuotas_vencidas");
    }
}
