<?php

namespace App\Command;

use App\Entity\TokenAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:initialize-token-actions',
    description: 'Inicializa las acciones de tokens con sus costos predeterminados',
)]
class InitializeTokenActionsCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $actions = [
            // Alumnos
            ['code' => 'alumno.create', 'name' => 'Crear Alumno', 'description' => 'Registrar un nuevo alumno en el sistema', 'cost' => 5],
            ['code' => 'alumno.edit', 'name' => 'Editar Alumno', 'description' => 'Modificar información de un alumno', 'cost' => 1],
            ['code' => 'alumno.delete', 'name' => 'Eliminar Alumno', 'description' => 'Eliminar un alumno del sistema', 'cost' => 2],
            
            // Profesores
            ['code' => 'profesor.create', 'name' => 'Crear Profesor', 'description' => 'Registrar un nuevo profesor', 'cost' => 5],
            ['code' => 'profesor.edit', 'name' => 'Editar Profesor', 'description' => 'Modificar información de un profesor', 'cost' => 1],
            ['code' => 'profesor.delete', 'name' => 'Eliminar Profesor', 'description' => 'Eliminar un profesor del sistema', 'cost' => 2],
            
            // Cursos
            ['code' => 'curso.create', 'name' => 'Crear Curso', 'description' => 'Crear un nuevo curso', 'cost' => 10],
            ['code' => 'curso.edit', 'name' => 'Editar Curso', 'description' => 'Modificar información de un curso', 'cost' => 2],
            ['code' => 'curso.delete', 'name' => 'Eliminar Curso', 'description' => 'Eliminar un curso del sistema', 'cost' => 5],
            
            // Pagos
            ['code' => 'pago.create', 'name' => 'Registrar Pago', 'description' => 'Registrar un nuevo pago', 'cost' => 3],
            ['code' => 'pago.edit', 'name' => 'Editar Pago', 'description' => 'Modificar un pago registrado', 'cost' => 1],
            ['code' => 'pago.delete', 'name' => 'Eliminar Pago', 'description' => 'Eliminar un pago del sistema', 'cost' => 2],
            
            // Notificaciones
            ['code' => 'notificacion.send', 'name' => 'Enviar Notificación', 'description' => 'Enviar email de recibo o recordatorio', 'cost' => 1],
            
            // Usuarios del sistema
            ['code' => 'usuario.create', 'name' => 'Crear Usuario', 'description' => 'Crear un nuevo usuario del sistema', 'cost' => 3],
            ['code' => 'usuario.edit', 'name' => 'Editar Usuario', 'description' => 'Modificar información de un usuario', 'cost' => 1],
            ['code' => 'usuario.delete', 'name' => 'Eliminar Usuario', 'description' => 'Eliminar un usuario del sistema', 'cost' => 2],
            
            // Vencimientos
            ['code' => 'vencimiento.create', 'name' => 'Crear Vencimiento', 'description' => 'Crear un nuevo vencimiento', 'cost' => 2],
            ['code' => 'vencimiento.edit', 'name' => 'Editar Vencimiento', 'description' => 'Modificar un vencimiento', 'cost' => 1],
            ['code' => 'vencimiento.delete', 'name' => 'Eliminar Vencimiento', 'description' => 'Eliminar un vencimiento', 'cost' => 1],
            
            // Descuentos
            ['code' => 'descuento.create', 'name' => 'Crear Descuento', 'description' => 'Crear un descuento promocional', 'cost' => 2],
            ['code' => 'descuento.edit', 'name' => 'Editar Descuento', 'description' => 'Modificar un descuento promocional', 'cost' => 1],
            ['code' => 'descuento.delete', 'name' => 'Eliminar Descuento', 'description' => 'Eliminar un descuento promocional', 'cost' => 1],
            
            // Exportación
            ['code' => 'export.excel', 'name' => 'Exportar a Excel', 'description' => 'Exportar datos a formato Excel', 'cost' => 5],
        ];

        $repository = $this->entityManager->getRepository(TokenAction::class);
        $created = 0;
        $updated = 0;

        foreach ($actions as $actionData) {
            $existing = $repository->findOneBy(['code' => $actionData['code']]);
            
            if ($existing) {
                // Actualizar si existe
                $existing->setName($actionData['name']);
                $existing->setDescription($actionData['description']);
                $existing->setCost($actionData['cost']);
                $existing->setActive(true);
                $existing->setUpdatedAt(new \DateTime());
                $updated++;
            } else {
                // Crear nuevo
                $action = new TokenAction();
                $action->setCode($actionData['code']);
                $action->setName($actionData['name']);
                $action->setDescription($actionData['description']);
                $action->setCost($actionData['cost']);
                $action->setActive(true);
                $this->entityManager->persist($action);
                $created++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Acciones de tokens inicializadas: %d creadas, %d actualizadas', $created, $updated));

        return Command::SUCCESS;
    }
}

