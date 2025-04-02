<?php

namespace App\Command;

use App\DataFixtures\AlumnoSeeder;
use App\DataFixtures\CursoSeeder;
use App\DataFixtures\InstitutoSeeder;
use App\DataFixtures\ProfesorSeeder;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SeedDatabaseCommand extends Command
{
    protected static $defaultName = 'app:seed-database';

    private $container;
    private $entityManager;
    private $io;

    public function __construct(ContainerInterface $container, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->container = $container;
        $this->entityManager = $entityManager;
    }

    protected function configure()
    {
        $this->setDescription('Seeds the database with initial data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Starting database seeding...');

        // Crear el loader
        $loader = new SymfonyFixturesLoader($this->container);

        // Obtener las instancias de los seeders con sus dependencias
        $institutoSeeder = $this->container->get(InstitutoSeeder::class);
        $cursoSeeder = $this->container->get(CursoSeeder::class);
        $profesorSeeder = $this->container->get(ProfesorSeeder::class);
        $alumnoSeeder = $this->container->get(AlumnoSeeder::class);

        // Registrar los fixtures
        $loader->addFixture($institutoSeeder);
        $loader->addFixture($cursoSeeder);
        $loader->addFixture($profesorSeeder);
        $loader->addFixture($alumnoSeeder);

        // Crear el purger y el executor
        $purger = new ORMPurger($this->entityManager);
        $executor = new ORMExecutor($this->entityManager, $purger);

        // Ejecutar los fixtures
        $this->io->section('Creating institute and admin user...');
        $executor->execute($loader->getFixtures(), true);

        $this->io->success('Database seeded successfully!');
        return Command::SUCCESS;
    }
} 