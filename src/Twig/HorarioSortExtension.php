<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class HorarioSortExtension extends AbstractExtension
{
    private const DIAS_ORDEN = [
        'Lunes' => 1,
        'Martes' => 2,
        'Miercoles' => 3,
        'Jueves' => 4,
        'Viernes' => 5,
        'Sabado' => 6,
        'Domingo' => 7,
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('sort_horarios', [$this, 'sortHorarios']),
        ];
    }

    public function sortHorarios($horarios): array
    {
        if (!is_iterable($horarios)) {
            return [];
        }

        $horariosArray = [];
        foreach ($horarios as $horario) {
            $horariosArray[] = $horario;
        }

        usort($horariosArray, function ($a, $b) {
            $diaA = self::DIAS_ORDEN[$a->getDia()] ?? 999;
            $diaB = self::DIAS_ORDEN[$b->getDia()] ?? 999;

            if ($diaA !== $diaB) {
                return $diaA <=> $diaB;
            }

            $horaA = $a->getHorarioInicio();
            $horaB = $b->getHorarioInicio();

            if ($horaA && $horaB) {
                return $horaA <=> $horaB;
            }

            return 0;
        });

        return $horariosArray;
    }
}
