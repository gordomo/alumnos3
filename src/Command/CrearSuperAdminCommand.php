<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crea el primer super admin de una instalación.
 *
 * Una base recién creada no tiene ningún usuario, así que no hay forma de entrar al sistema ni de
 * crear el primer instituto: el panel que haría falta está detrás del login. Esto rompe ese
 * círculo, y es lo único que hace.
 *
 * La contraseña se pide por teclado y no se muestra: así no queda en el historial de la terminal
 * ni en los logs del servidor. La opción --password existe para automatizar, con la advertencia
 * de que ahí sí queda escrita.
 */
#[AsCommand(
    name: 'app:crear-super-admin',
    description: 'Crea un usuario super admin, para arrancar una instalación nueva',
)]
class CrearSuperAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $usuarios,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'El email con el que va a entrar')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'La contraseña (si no, se pide por teclado)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Ese email no es válido: ' . $email);

            return Command::FAILURE;
        }

        if ($this->usuarios->findOneBy(['email' => $email])) {
            $io->error('Ya existe un usuario con ese email. Si perdiste la contraseña, usá "olvidé mi contraseña" en el login.');

            return Command::FAILURE;
        }

        $password = $input->getOption('password');

        if (!$password) {
            $pregunta = new Question('Contraseña para ' . $email . ': ');
            $pregunta->setHidden(true);
            $pregunta->setHiddenFallback(false);
            $password = $io->askQuestion($pregunta);
        }

        if (!is_string($password) || strlen($password) < 8) {
            $io->error('La contraseña tiene que tener al menos 8 caracteres.');

            return Command::FAILURE;
        }

        $usuario = new User();
        $usuario->setEmail($email);
        $usuario->setRoles(['ROLE_SUPER_ADMIN']);
        $usuario->setPassword($this->passwordHasher->hashPassword($usuario, $password));

        $this->em->persist($usuario);
        $this->em->flush();

        $io->success('Super admin creado: ' . $email);
        $io->writeln('Entrá por /login y vas a caer en el listado de institutos.');

        return Command::SUCCESS;
    }
}
