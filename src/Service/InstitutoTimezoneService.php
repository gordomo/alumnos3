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
     * Fecha civil actual del instituto, normalizada como fecha sin hora.
     * Se almacena a medianoche UTC para evitar corrimientos con campos date/input type=date.
     */
    public function getCurrentDateForInstituto(Instituto $instituto): \DateTime
    {
        return $this->normalizeDateOnly($this->getNowForInstituto($instituto));
    }

    /**
     * Normaliza una fecha a "solo fecha" preservando su día calendario.
     * Se usa medianoche UTC para evitar desplazamientos por timezone en formularios HTML date.
     */
    public function normalizeDateOnly(\DateTimeInterface $date): \DateTime
    {
        $normalized = \DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $date->format('Y-m-d') . ' 00:00:00',
            new \DateTimeZone('UTC')
        );

        if ($normalized === false) {
            return new \DateTime($date->format('Y-m-d'), new \DateTimeZone('UTC'));
        }

        return $normalized;
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
     * Parsea una fecha enviada por el usuario (query string, formularios).
     * Si se pasa $preferredFormat (ej. del instituto: d/m/Y, m/d/Y), se intenta ese formato primero.
     * Así se evita interpretar 04/05 como 4-may (d/m) cuando el usuario envió 5-abr (m/d).
     */
    public function parseDateString(string $str, ?string $preferredFormat = null): ?\DateTime
    {
        if ($str === '') {
            return null;
        }
        $formats = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y'];
        if ($preferredFormat !== null && $preferredFormat !== '') {
            $formats = array_unique(array_merge([$preferredFormat], $formats));
        }
        foreach ($formats as $format) {
            // El '!' inicial resetea los campos no presentes en el formato, así la fecha
            // queda a las 00:00:00. Sin eso createFromFormat arrastra la hora actual, y
            // una fecha usada como límite de un rango (p.fecha <= :hasta) dejaba afuera
            // lo cargado más tarde ese mismo día.
            $d = \DateTime::createFromFormat('!' . $format, $str);
            if ($d === false) {
                continue;
            }

            // createFromFormat es permisivo: '32/13/2026' desborda a otra fecha en lugar
            // de fallar. Se descarta si hubo warnings o errores.
            $errores = \DateTime::getLastErrors();
            if (is_array($errores) && ($errores['warning_count'] > 0 || $errores['error_count'] > 0)) {
                continue;
            }

            return $d;
        }
        return null;
    }

    /**
     * Igual que parseDateString(), pero al final del día (23:59:59).
     *
     * Para usar como límite superior inclusivo de un rango sobre columnas datetime:
     * con la fecha a medianoche, `campo <= :hasta` excluiría todo lo de ese mismo día.
     */
    public function parseDateStringEndOfDay(string $str, ?string $preferredFormat = null): ?\DateTime
    {
        $fecha = $this->parseDateString($str, $preferredFormat);

        return $fecha?->setTime(23, 59, 59);
    }
}
