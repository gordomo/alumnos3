<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        // Fijar zona horaria de la app (fechas, "hoy", asistencias). Evita que dependa del servidor.
        $timezone = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE');
        if ($timezone) {
            date_default_timezone_set($timezone);
        }
    }
}
