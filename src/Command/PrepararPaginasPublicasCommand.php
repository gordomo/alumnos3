<?php
namespace App\Command;

use App\Entity\Instituto;
use App\Service\InstitutoPaginaPublicaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PrepararPaginasPublicasCommand extends Command
{
    protected static $defaultName = 'app:paginas-publicas:preparar';
    protected static $defaultDescription = 'Reserva una URL y crea un borrador para cada institución activa; no publica páginas.';
    public function __construct(private EntityManagerInterface $em, private InstitutoPaginaPublicaService $paginas) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $institutos = $this->em->getRepository(Instituto::class)->findBy(['activo' => true], ['id' => 'ASC']);
        foreach ($institutos as $instituto) { $this->paginas->obtenerOCrear($instituto); }
        $output->writeln(sprintf('Direcciones preparadas para %d instituciones. Se conserva el estado de publicación existente.', count($institutos)));
        return Command::SUCCESS;
    }
}
