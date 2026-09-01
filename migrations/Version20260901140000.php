<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Token del enlace de la familia.
 *
 * Cuatro columnas nuevas en alumno, todas nulas o en cero: mientras no se genere un enlace, nada
 * cambia. El tutor entra por /familia/{token} sin usuario ni contraseña, y las otras tres
 * columnas son para saber cuándo se generó y si se usó.
 */
final class Version20260901140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'alumno: token del enlace de la familia y registro de accesos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alumno ADD token_tutor VARCHAR(64) DEFAULT NULL, ADD token_tutor_creado_en DATETIME DEFAULT NULL, ADD token_tutor_ultimo_acceso DATETIME DEFAULT NULL, ADD token_tutor_accesos INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1435D52D3AD94209 ON alumno (token_tutor)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_1435D52D3AD94209 ON alumno');
        $this->addSql('ALTER TABLE alumno DROP token_tutor, DROP token_tutor_creado_en, DROP token_tutor_ultimo_acceso, DROP token_tutor_accesos');
    }
}
