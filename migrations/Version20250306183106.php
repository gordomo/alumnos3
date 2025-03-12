<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250306183106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea un usuario inicial con rol AdministradorDeInstitutos';
    }

    public function up(Schema $schema): void
    {
        // Usa el hash que se obtuvo manualmente
        $hashedPassword = password_hash("M021415p$", PASSWORD_DEFAULT);

        // Inserta el usuario inicial
        $this->addSql('INSERT INTO user (email, roles, password) VALUES (?, ?, ?)', [
            'superadmin@domain.com', // Email
            json_encode(['ROLE_ADMIN_INSTITUTO']), // Rol
            $hashedPassword, // Password hasheada
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM user WHERE email = ?', ['superadmin@domain.com']);
    }
}