<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class DuracionFormatExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_duracion', [$this, 'formatDuracion']),
        ];
    }

    /**
     * Formatea una duración en horas decimales a formato "Xh Ym"
     * Ejemplo: 1.5 -> "1h 30m", 2.25 -> "2h 15m", 1.0 -> "1h"
     */
    public function formatDuracion(?float $duracion): string
    {
        if ($duracion === null || $duracion <= 0) {
            return '-';
        }

        $horas = (int) floor($duracion);
        $minutos = (int) round(($duracion - $horas) * 60);

        if ($minutos === 0) {
            return $horas . 'h';
        }

        return $horas . 'h ' . $minutos . 'm';
    }
}
