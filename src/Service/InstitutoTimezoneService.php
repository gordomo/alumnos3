<?php

namespace App\Service;

use App\Entity\Instituto;
use App\Repository\InstitutoConfiguracionRepository;

/**
 * Devuelve la zona horaria y la fecha "hoy" según la configuración del instituto.
 * Si el instituto no tiene timezone configurada, se usa APP_TIMEZONE o la del servidor.
 */
class InstitutoTimezoneService
{
    public function __construct(
        private InstitutoConfiguracionRepository $configuracionRepository
    ) {
    }

    /**
     * Zona horaria efectiva para el instituto (identificador PHP, ej: America/Argentina/Buenos_Aires).
     */
    public function getTimezoneForInstituto(Instituto $instituto): string
    {
        $config = $this->configuracionRepository->findOneBy(['instituto' => $instituto]);
        $tz = $config?->getTimezone();
        if ($tz !== null && $tz !== '') {
            try {
                new \DateTimeZone($tz);
                return $tz;
            } catch (\Exception $e) {
                // valor inválido, caer al default
            }
        }
        $default = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE');
        return $default !== false && $default !== '' ? $default : date_default_timezone_get();
    }

    /**
     * Fecha de hoy en la zona horaria del instituto (Y-m-d).
     */
    public function getTodayForInstituto(Instituto $instituto): string
    {
        return $this->getNowForInstituto($instituto)->format('Y-m-d');
    }

    /**
     * "Ahora" en la zona horaria del instituto (para formatear año, mes, día, etc.).
     */
    public function getNowForInstituto(Instituto $instituto): \DateTimeImmutable
    {
        $tz = $this->getTimezoneForInstituto($instituto);
        return new \DateTimeImmutable('now', new \DateTimeZone($tz));
    }

    /**
     * Formato de fecha para mostrar en la aplicación (ej: d/m/Y, m/d/Y, Y-m-d).
     * Si el instituto no tiene formato configurado, se usa d/m/Y.
     */
    public function getDateFormatForInstituto(Instituto $instituto): string
    {
        $config = $this->configuracionRepository->findOneBy(['instituto' => $instituto]);
        $format = $config?->getDateFormat();
        if ($format !== null && $format !== '') {
            return $format;
        }
        return 'd/m/Y';
    }

    /**
     * Parsea una fecha en formato d/m/Y o Y-m-d.
     * Usar para fechas enviadas por el usuario (formularios, query string).
     */
    public function parseDateString(string $str): ?\DateTime
    {
        if ($str === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('d/m/Y', $str);
        if ($d !== false) {
            return $d;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $str);
        if ($d !== false) {
            return $d;
        }
        $d = \DateTime::createFromFormat('m/d/Y', $str);
        if ($d !== false) {
            return $d;
        }
        $d = \DateTime::createFromFormat('d-m-Y', $str);
        return $d !== false ? $d : null;
    }
}
