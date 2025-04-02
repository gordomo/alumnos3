<?php

namespace App\DataFixtures;

use App\Entity\Instituto;
use App\Entity\User;
use App\Entity\Vencimiento;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class InstitutoSeeder extends Fixture
{
    private $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // Crear el instituto
        $instituto = new Instituto();
        $instituto->setNombre('GatoFly');
        $instituto->setEmail('gatofly12@hotmail.com');
        $instituto->setDir('Av. Principal 123');
        $instituto->setTel('1234567890');
        $manager->persist($instituto);

        // Crear vencimientos
        $vencimiento1 = new Vencimiento();
        $vencimiento1->setDiaVencimiento(5);
        $vencimiento1->setPorcentajeInteres(10);
        $vencimiento1->setOrden(1);
        $vencimiento1->setInstituto($instituto);
        $manager->persist($vencimiento1);

        $vencimiento2 = new Vencimiento();
        $vencimiento2->setDiaVencimiento(15);
        $vencimiento2->setPorcentajeInteres(20);
        $vencimiento2->setOrden(2);
        $vencimiento2->setInstituto($instituto);
        $manager->persist($vencimiento2);

        // Crear usuario admin
        $user = new User();
        $user->setEmail('gatofly12@hotmail.com');
        $user->setRoles(['ROLE_ADMIN_INSTITUTO']);
        $user->setInstituto($instituto);

        $hashedPassword = $this->passwordHasher->hashPassword($user, 'MoriCat2');
        $user->setPassword($hashedPassword);

        $manager->persist($user);
        $manager->flush();

        // Guardar referencia para otros seeders
        $this->addReference('instituto', $instituto);
    }
} 