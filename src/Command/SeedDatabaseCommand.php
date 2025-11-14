<?php

namespace App\Command;

use App\DataFixtures\UserSeeder;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SeedDatabaseCommand extends Command
{
    protected static $defaultName = 'app:seed-database';
    protected static $defaultDescription = 'Seed the database with initial data';

    private $entityManager;
    private $userSeeder;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserSeeder $userSeeder
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->userSeeder = $userSeeder;
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // Crear el purger y el executor
            $purger = new ORMPurger($this->entityManager);
            $executor = new ORMExecutor($this->entityManager, $purger);

            // Cargar el seeder
            $loader = new SymfonyFixturesLoader($this->getApplication()->getKernel()->getContainer());
            $loader->addFixture($this->userSeeder);

            // Ejecutar los seeders
            $executor->execute($loader->getFixtures());

            $io->success('Database seeded successfully!');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error seeding database: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
} 