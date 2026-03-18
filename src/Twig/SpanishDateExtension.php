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
            // Intentar parsear diferentes formatos comunes
            // Formato d/m/Y (18/03/2026)
            $parsed = \DateTime::createFromFormat('d/m/Y', $date);
            if ($parsed === false) {
                // Formato Y-m-d (2026-03-18)
                $parsed = \DateTime::createFromFormat('Y-m-d', $date);
            }
            if ($parsed === false) {
                // Intentar con el parser por defecto
                try {
                    $parsed = new \DateTime($date);
                } catch (\Exception $e) {
                    return 'Fecha inválida';
                }
            }
            $date = $parsed;
        }
        $mes = (int) $date->format('n');
        $anio = $date->format('Y');
        return self::MESES[$mes] . ' ' . $anio;
    }
}
