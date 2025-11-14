<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserSeeder extends Fixture
{
    private $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // Verificar si ya existe un super admin
        $existingAdmin = $manager->getRepository(User::class)->findOneBy(['email' => 'admin@admin.com']);
        if ($existingAdmin) {
            echo "\nEl super admin ya existe en la base de datos.\n";
            return;
        }

        // Crear super admin
        $admin = new User();
        $admin->setEmail('admin@admin.com');
        $admin->setRoles(['ROLE_ADMIN']);
        
        // La contraseña por defecto será 'admin123' - el usuario deberá cambiarla en el primer inicio de sesión
        $hashedPassword = $this->passwordHasher->hashPassword($admin, 'admin123');
        $admin->setPassword($hashedPassword);
        
        $manager->persist($admin);
        $manager->flush();

        echo "\nSuper admin creado exitosamente:\n";
        echo "Email: admin@admin.com\n";
        echo "Contraseña: admin123\n";
        echo "\nIMPORTANTE: Por favor, cambia la contraseña después del primer inicio de sesión.\n";
    }

    public static function getGroups(): array
    {
        return ['default'];
    }
} 