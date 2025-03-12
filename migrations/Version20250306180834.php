<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250306180834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade instituto_id a varias tablas y crea claves foráneas';
    }

    public function up(Schema $schema): void
    {
        // Suponiendo que el primer instituto tiene id=1
        $defaultInstitutoId = 1;

        // Añadir la columna instituto_id en 'alumno'
        $this->addSql('ALTER TABLE alumno ADD instituto_id INT DEFAULT NULL');
        $this->addSql("UPDATE alumno SET instituto_id = $defaultInstitutoId WHERE instituto_id IS NULL");
        $this->addSql('ALTER TABLE alumno CHANGE instituto_id instituto_id INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE alumno ADD CONSTRAINT FK_1435D52D6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('CREATE INDEX IDX_1435D52D6C6EF28 ON alumno (instituto_id)');

        // Repite el mismo proceso para 'curso', 'profesor' y 'user'
        $this->addSql('ALTER TABLE curso ADD instituto_id INT DEFAULT NULL');
        $this->addSql("UPDATE curso SET instituto_id = $defaultInstitutoId WHERE instituto_id IS NULL");
        $this->addSql('ALTER TABLE curso CHANGE instituto_id instituto_id INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE curso ADD CONSTRAINT FK_CA3B40EC6C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('CREATE INDEX IDX_CA3B40EC6C6EF28 ON curso (instituto_id)');

        $this->addSql('ALTER TABLE profesor ADD instituto_id INT DEFAULT NULL');
        $this->addSql("UPDATE profesor SET instituto_id = $defaultInstitutoId WHERE instituto_id IS NULL");
        $this->addSql('ALTER TABLE profesor CHANGE instituto_id instituto_id INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE profesor ADD CONSTRAINT FK_5B7406D96C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('CREATE INDEX IDX_5B7406D96C6EF28 ON profesor (instituto_id)');

        $this->addSql('ALTER TABLE user ADD instituto_id INT DEFAULT NULL');
        $this->addSql("UPDATE user SET instituto_id = $defaultInstitutoId WHERE instituto_id IS NULL");
        $this->addSql('ALTER TABLE user CHANGE instituto_id instituto_id INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D6496C6EF28 FOREIGN KEY (instituto_id) REFERENCES instituto (id)');
        $this->addSql('CREATE INDEX IDX_8D93D6496C6EF28 ON user (instituto_id)');
    }

    public function down(Schema $schema): void
    {
        // Operaciones para revertir la migración
        $this->addSql('ALTER TABLE alumno DROP FOREIGN KEY FK_1435D52D6C6EF28');
        $this->addSql('DROP INDEX IDX_1435D52D6C6EF28 ON alumno');
        $this->addSql('ALTER TABLE alumno DROP instituto_id');

        $this->addSql('ALTER TABLE curso DROP FOREIGN KEY FK_CA3B40EC6C6EF28');
        $this->addSql('DROP INDEX IDX_CA3B40EC6C6EF28 ON curso');
        $this->addSql('ALTER TABLE curso DROP instituto_id');

        $this->addSql('ALTER TABLE profesor DROP FOREIGN KEY FK_5B7406D96C6EF28');
        $this->addSql('DROP INDEX IDX_5B7406D96C6EF28 ON profesor');
        $this->addSql('ALTER TABLE profesor DROP instituto_id');

        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D6496C6EF28');
        $this->addSql('DROP INDEX IDX_8D93D6496C6EF28 ON user');
        $this->addSql('ALTER TABLE user DROP instituto_id');
    }
}