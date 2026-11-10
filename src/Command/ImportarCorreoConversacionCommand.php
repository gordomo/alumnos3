<?php
namespace App\Command;
use App\Service\ImportarCorreoConversacion;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
class ImportarCorreoConversacionCommand extends Command
{
    protected static $defaultName='app:correo:importar';
    protected static $defaultDescription='Importa un correo de una casilla central desde JSON por stdin (uso local del recolector).';
    public function __construct(private ImportarCorreoConversacion $importador) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $entrada=stream_get_contents(STDIN,8*1024*1024+1);
            if (strlen($entrada)>8*1024*1024) { throw new \InvalidArgumentException('Entrada demasiado extensa.'); }
            $datos=json_decode($entrada,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($datos)) { throw new \InvalidArgumentException('Entrada inválida.'); }
            $r=$this->importador->importar($datos);$output->writeln(json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            return $r['estado']==='deshabilitado'?Command::FAILURE:Command::SUCCESS;
        } catch (\Throwable $e) { $output->writeln('{"estado":"error_temporal"}');return Command::FAILURE; }
    }
}
