<?php

namespace App\Service;

use App\Entity\Calificacion;
use App\Entity\ConceptoCalificacion;
use App\Entity\Evaluacion;
use App\Entity\Instituto;
use App\Entity\InstitutoConfiguracion;
use App\Repository\ConceptoCalificacionRepository;

/**
 * Resuelve la escala de calificación vigente y valida notas contra ella.
 *
 * La escala se configura por instituto y puede ser numérica, conceptual o ambas. Cada
 * Evaluacion guarda un snapshot de la escala con la que se creó, así que cambiar la
 * configuración del instituto no reinterpreta notas ya cargadas.
 */
class EscalaCalificacionService
{
    public function __construct(
        private ConceptoCalificacionRepository $conceptoRepository
    ) {
    }

    public function usaCalificaciones(?Instituto $instituto): bool
    {
        $config = $instituto ? $instituto->getConfiguracion() : null;

        return $config ? $config->usaCalificaciones() : false;
    }

    /**
     * Escala vigente del instituto.
     *
     * @return array{modo: string, min: float|null, max: float|null, aprobacion: float|null, conceptos: ConceptoCalificacion[]}
     */
    public function getEscalaParaInstituto(Instituto $instituto): array
    {
        $config = $instituto->getConfiguracion();

        if (!$config || !$config->usaCalificaciones()) {
            return $this->escalaVacia();
        }

        return [
            'modo' => $config->getModoCalificacion(),
            'min' => $config->getNotaMinima(),
            'max' => $config->getNotaMaxima(),
            'aprobacion' => $config->getNotaAprobacion(),
            'conceptos' => $config->usaConcepto()
                ? $this->conceptoRepository->findByInstituto($instituto)
                : [],
        ];
    }

    /**
     * Escala de una evaluación: su snapshot, con fallback a la del instituto.
     *
     * @return array{modo: string, min: float|null, max: float|null, aprobacion: float|null, conceptos: ConceptoCalificacion[]}
     */
    public function getEscalaParaEvaluacion(Evaluacion $evaluacion): array
    {
        $modo = $evaluacion->getTipoEscala() ?: InstitutoConfiguracion::MODO_NINGUNO;

        if ($modo === InstitutoConfiguracion::MODO_NINGUNO) {
            return $this->escalaVacia();
        }

        $usaConcepto = in_array($modo, [InstitutoConfiguracion::MODO_CONCEPTUAL, InstitutoConfiguracion::MODO_AMBOS], true);

        return [
            'modo' => $modo,
            'min' => $evaluacion->getNotaMinima(),
            'max' => $evaluacion->getNotaMaxima(),
            'aprobacion' => $evaluacion->getNotaAprobacion(),
            'conceptos' => $usaConcepto && $evaluacion->getInstituto()
                ? $this->conceptoRepository->findByInstituto($evaluacion->getInstituto())
                : [],
        ];
    }

    /**
     * Copia la escala vigente del instituto al snapshot de la evaluación.
     */
    public function aplicarSnapshot(Evaluacion $evaluacion): void
    {
        $instituto = $evaluacion->getInstituto();
        if (!$instituto) {
            return;
        }

        $escala = $this->getEscalaParaInstituto($instituto);
        $evaluacion->setTipoEscala($escala['modo']);
        $evaluacion->setNotaMinima($escala['min']);
        $evaluacion->setNotaMaxima($escala['max']);
        $evaluacion->setNotaAprobacion($escala['aprobacion']);
    }

    /**
     * Valida un valor contra la escala. Devuelve los mensajes de error encontrados.
     *
     * @param array{modo: string, min: float|null, max: float|null, aprobacion: float|null, conceptos: ConceptoCalificacion[]} $escala
     * @return string[]
     */
    public function validar(array $escala, ?float $valorNumerico, ?ConceptoCalificacion $concepto, bool $ausente): array
    {
        $errores = [];

        if ($ausente) {
            // Un ausente no lleva nota: si vinieron valores, se ignoran silenciosamente
            // en el servicio de carga, no es un error del operador.
            return $errores;
        }

        $usaNumerica = in_array($escala['modo'], [InstitutoConfiguracion::MODO_NUMERICO, InstitutoConfiguracion::MODO_AMBOS], true);
        $usaConcepto = in_array($escala['modo'], [InstitutoConfiguracion::MODO_CONCEPTUAL, InstitutoConfiguracion::MODO_AMBOS], true);

        if ($valorNumerico !== null) {
            if (!$usaNumerica) {
                $errores[] = 'La escala configurada no admite notas numéricas.';
            } else {
                if ($escala['min'] !== null && $valorNumerico < $escala['min']) {
                    $errores[] = sprintf('La nota no puede ser menor que %s.', $this->formatearNumero($escala['min']));
                }
                if ($escala['max'] !== null && $valorNumerico > $escala['max']) {
                    $errores[] = sprintf('La nota no puede ser mayor que %s.', $this->formatearNumero($escala['max']));
                }
            }
        }

        if ($concepto !== null && !$usaConcepto) {
            $errores[] = 'La escala configurada no admite conceptos.';
        }

        return $errores;
    }

    /**
     * ¿Esta nota aprueba? null si no se puede determinar.
     */
    public function apruebaCalificacion(array $escala, Calificacion $calificacion): ?bool
    {
        if ($calificacion->isAusente()) {
            return false;
        }

        // El concepto manda cuando existe: es una decisión explícita del profesor.
        if ($calificacion->getConcepto() !== null) {
            return $calificacion->getConcepto()->isAprueba();
        }

        $valor = $calificacion->getValorNumerico();
        if ($valor !== null && $escala['aprobacion'] !== null) {
            return $valor >= $escala['aprobacion'];
        }

        return null;
    }

    /**
     * Valida que un concepto pertenezca al instituto. Un select se edita desde devtools.
     */
    public function conceptoPerteneceAInstituto(?ConceptoCalificacion $concepto, Instituto $instituto): bool
    {
        if ($concepto === null) {
            return true;
        }

        return $concepto->getInstituto() === $instituto
            || ($concepto->getInstituto() && $concepto->getInstituto()->getId() === $instituto->getId());
    }

    /**
     * @return array{modo: string, min: null, max: null, aprobacion: null, conceptos: array}
     */
    private function escalaVacia(): array
    {
        return [
            'modo' => InstitutoConfiguracion::MODO_NINGUNO,
            'min' => null,
            'max' => null,
            'aprobacion' => null,
            'conceptos' => [],
        ];
    }

    private function formatearNumero(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }
}
