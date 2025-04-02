<?php

namespace App\DataFixtures;

use App\Entity\Instituto;
use App\Entity\Profesor;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProfesorSeeder extends Fixture implements DependentFixtureInterface
{
    private $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Instituto $instituto */
        $instituto = $this->getReference('instituto', Instituto::class);

        $profesores = [
            [
                'nombre' => 'María',
                'apellido' => 'González',
                'email' => 'maria.gonzalez@gatofly.com',
                'password' => 'Profesor123',
                'dni' => '12345678',
                'telefono' => '1234567890',
                'direccion' => 'Calle Principal 123',
                'fecha_nacimiento' => new \DateTime('1985-05-15'),
                'fecha_ingreso' => new \DateTime('2024-01-01'),
                'sueldo' => 5000
            ],
            [
                'nombre' => 'Juan',
                'apellido' => 'Pérez',
                'email' => 'juan.perez@gatofly.com',
                'password' => 'Profesor123',
                'dni' => '87654321',
                'telefono' => '0987654321',
                'direccion' => 'Avenida Central 456',
                'fecha_nacimiento' => new \DateTime('1990-08-20'),
                'fecha_ingreso' => new \DateTime('2024-01-15'),
                'sueldo' => 4500
            ],
            [
                'nombre' => 'Ana',
                'apellido' => 'Martínez',
                'email' => 'ana.martinez@gatofly.com',
                'password' => 'Profesor123',
                'dni' => '11223344',
                'telefono' => '1122334455',
                'direccion' => 'Plaza Mayor 789',
                'fecha_nacimiento' => new \DateTime('1988-03-10'),
                'fecha_ingreso' => new \DateTime('2024-02-01'),
                'sueldo' => 4800
            ]
        ];

        foreach ($profesores as $profesorData) {
            $profesor = new Profesor();
            $profesor->setNombre($profesorData['nombre']);
            $profesor->setApellido($profesorData['apellido']);
            $profesor->setEmail($profesorData['email']);
            $profesor->setDni($profesorData['dni']);
            $profesor->setTel($profesorData['telefono']);
            $profesor->setPrecioHora($profesorData['sueldo']);
            $profesor->setInstituto($instituto);

            $user = new User();
            $user->setEmail($profesorData['email']);
            $user->setRoles(['ROLE_PROFESOR']);
            $user->setInstituto($instituto);

            $hashedPassword = $this->passwordHasher->hashPassword(
                $user,
                $profesorData['password']
            );
            $user->setPassword($hashedPassword);

            $manager->persist($profesor);
            $manager->persist($user);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            InstitutoSeeder::class
        ];
    }
} 