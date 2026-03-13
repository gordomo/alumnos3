<?php

namespace App\Twig;

use App\Entity\Alumno;
use App\Service\DeudaCalculatorService;
use App\Service\InstitutoTimezoneService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class DeudaExtension extends AbstractExtension
{
    private $deudaCalculator;
    private $timezoneService;

    public function __construct(
        DeudaCalculatorService $deudaCalculator,
        InstitutoTimezoneService $timezoneService
    ) {
        $this->deudaCalculator = $deudaCalculator;
        $this->timezoneService = $timezoneService;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tiene_deudas_vencidas', [$this, 'tieneDeudasVencidas']),
        ];
    }

    /**
     * Verifica si un alumno tiene deudas vencidas calculadas on-demand
     */
    public function tieneDeudasVencidas(Alumno $alumno): bool
    {
        if (!$alumno->getActivo()) {
            return false;
        }

        $instituto = $alumno->getInstituto();
        $fechaActual = $this->timezoneService->getNowForInstituto($instituto);
        $diaActual = (int)$fechaActual->format('d');
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');

        // Obtener primer día de vencimiento
        $vencimientos = $instituto->getVencimientos();
        $primerDiaVencimiento = 5; // Default
        
        if (count($vencimientos) > 0) {
            $vencimientosArray = $vencimientos->toArray();
            usort($vencimientosArray, function($a, $b) {
                return $a->getDiaVencimiento() - $b->getDiaVencimiento();
            });
            $primerDiaVencimiento = $vencimientosArray[0]->getDiaVencimiento();
        }

        // Calcular deudas del alumno
        $deudas = $this->deudaCalculator->calcularDeudasAlumno($alumno);

        foreach ($deudas as $deuda) {
            $mesDeuda = $deuda['mes'];
            $anoDeuda = $deuda['ano'];

            // Deudas de meses anteriores están vencidas
            if ($anoDeuda < $anoActual || ($anoDeuda == $anoActual && $mesDeuda < $mesActual)) {
                return true;
            }

            // Deuda del mes actual vencida si ya pasó el primer día de vencimiento
            if ($anoDeuda == $anoActual && $mesDeuda == $mesActual && $diaActual >= $primerDiaVencimiento) {
                return true;
            }
        }

        return false;
    }
}
