<?php
namespace App\Command;
use App\Service\MercadoPagoService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'tmp:mp-pago')]
class TmpMpPagoCommand extends Command {
    public function __construct(private MercadoPagoService $mp) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('id', InputArgument::REQUIRED); }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        try {
            $p = $this->mp->buscarPago((string) $i->getArgument('id'));
            foreach (['id','status','status_detail','transaction_amount','external_reference','live_mode','date_approved','notification_url'] as $k) {
                $v = $p[$k] ?? null;
                $o->writeln(sprintf('  %-20s %s', $k, is_bool($v) ? ($v?'true':'false') : (is_scalar($v) ? $v : '-')));
            }
        } catch (\Throwable $e) {
            $o->writeln('ERROR: ' . $e->getMessage());
        }
        return Command::SUCCESS;
    }
}
