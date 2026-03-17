<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class SpanishDateExtension extends AbstractExtension
{
    private const MESES = [
        1 => 'Enero',
        2 => 'Febrero',
        3 => 'Marzo',
        4 => 'Abril',
        5 => 'Mayo',
        6 => 'Junio',
        7 => 'Julio',
        8 => 'Agosto',
        9 => 'Septiembre',
        10 => 'Octubre',
        11 => 'Noviembre',
        12 => 'Diciembre'
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('mes_es', [$this, 'formatMesEspanol']),
        ];
    }

    public function formatMesEspanol(\DateTimeInterface|string $date): string
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }
        $mes = (int) $date->format('n');
        $anio = $date->format('Y');
        return self::MESES[$mes] . ' ' . $anio;
    }
}
