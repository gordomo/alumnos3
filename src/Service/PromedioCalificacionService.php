<?php

namespace App\Service;

use App\Entity\AlumnoCursoHistorico;
use App\Entity\Calificacion;
use App\Entity\Curso;
use App\Entity\InstitutoConfiguracion;
use App\Repository\CalificacionRepository;

/**
 * Calcula promedios y decide si las calificaciones de un alumno aprueban.
 *
 * Sigue el criterio de DeudaCalculatorService: una sola query por curso y cero por alumno.
 */
class PromedioCalificacionService
{
    public const RESULTADO_APROBADO = 'aprobado';
    public const RESULTADO_DESAPROBADO = 'desaprobado';
    public const RESULTADO_SIN_DATOS = 'sin_datos';

    public function __construct(
        private CalificacionRepository $calificacionRepository,
        private EscalaCalificacionService $escalaService
    ) {
    }

    /**
     * Resumen de notas de todos los alumnos de un curso, indexado por id de histórico.
     *
     * @return array<int, array{promedio: float|null, cantidad: int, cargadas: int, aprobadas: int, desaprobadas: int, resultado: string}>
     */
    public function calcularParaCurso(Curso $curso): array
    {
        $instituto = $curso->getInstituto();
        $config = $instituto ? $instituto->getConfiguracion() : null;
        $criterio = $config ? $config->getCriterioAprobacionNotas() : InstitutoConfiguracion::CRITERIO_PROMEDIO;

        $porHistorico = $this->calificacionRepository->findByCursoAgrupadoPorHistorico($curso);

        $resumenes = [];
        foreach ($porHistorico as $historicoId => $calificaciones) {
            $resumenes[$historicoId] = $this->resumir($calificaciones, $criterio);
        }

        return $resumenes;
    }

    /**
     * Resumen de un histórico puntual.
     *
     * @return array{promedio: float|null, cantidad: int, cargadas: int, aprobadas: int, desaprobadas: int, resultado: string}
     */
    public function calcularParaHistorico(AlumnoCursoHistorico $historico): array
    {
        $curso = $historico->getCurso();
        $instituto = $curso ? $curso->getInstituto() : null;
        $config = $instituto ? $instituto->getConfiguracion() : null;
        $criterio = $config ? $config->getCriterioAprobacionNotas() : InstitutoConfiguracion::CRITERIO_PROMEDIO;

        return $this->resumir($this->calificacionRepository->findByHistorico($historico), $criterio);
    }

    /**
     * Resumen vacío, para alumnos sin ninguna nota cargada.
     *
     * @return array{promedio: null, cantidad: int, cargadas: int, aprobadas: int, desaprobadas: int, resultado: string}
     */
    public function resumenVacio(): array
    {
        return [
            'promedio' => null,
            'cantidad' => 0,
            'cargadas' => 0,
            'aprobadas' => 0,
            'desaprobadas' => 0,
            'resultado' => self::RESULTADO_SIN_DATOS,
        ];
    }

    /**
     * Núcleo del cálculo. Recibe las calificaciones ya cargadas, así que no toca la base:
     * es la parte que conviene cubrir con tests.
     *
     * Reglas:
     *  - Solo cuentan las evaluaciones marcadas como que cuentan para el promedio.
     *  - Un ausente se excluye del promedio, no cuenta como cero, pero sí desaprueba
     *    a los efectos del criterio 'todas'.
     *  - El promedio es ponderado por el peso de cada evaluación; con el peso por default
     *    en 1 equivale al promedio simple.
     *  - Sin ninguna nota utilizable el resultado es 'sin_datos', que nunca desaprueba.
     *
     * @param Calificacion[] $calificaciones
     * @return array{promedio: float|null, cantidad: int, cargadas: int, aprobadas: int, desaprobadas: int, resultado: string}
     */
    public function resumir(array $calificaciones, string $criterio = InstitutoConfiguracion::CRITERIO_PROMEDIO): array
    {
        $sumaPonderada = 0.0;
        $sumaPesos = 0.0;
        $cargadas = 0;
        $aprobadas = 0;
        $desaprobadas = 0;
        $indeterminadas = 0;

        foreach ($calificaciones as $calificacion) {
            $evaluacion = $calificacion->getEvaluacion();
            if (!$evaluacion || !$evaluacion->isCuentaParaPromedio()) {
                continue;
            }

            if (!$calificacion->tieneValor()) {
                continue;
            }

            $cargadas++;

            $valor = $calificacion->getValorParaPromedio();
            if ($valor !== null) {
                $peso = $evaluacion->getPeso();
                if ($peso > 0) {
                    $sumaPonderada += $valor * $peso;
                    $sumaPesos += $peso;
                }
            }

            $escala = $this->escalaService->getEscalaParaEvaluacion($evaluacion);
            $aprueba = $this->escalaService->apruebaCalificacion($escala, $calificacion);
            if ($aprueba === true) {
                $aprobadas++;
            } elseif ($aprueba === false) {
                $desaprobadas++;
            } else {
                $indeterminadas++;
            }
        }

        $promedio = $sumaPesos > 0 ? round($sumaPonderada / $sumaPesos, 2) : null;

        return [
            'promedio' => $promedio,
            'cantidad' => count($calificaciones),
            'cargadas' => $cargadas,
            'aprobadas' => $aprobadas,
            'desaprobadas' => $desaprobadas,
            'resultado' => $this->decidirResultado(
                $criterio,
                $cargadas,
                $aprobadas,
                $desaprobadas,
                $indeterminadas,
                $promedio,
                $this->aprobacionDeLaEscala($calificaciones)
            ),
        ];
    }

    /**
     * Decide el resultado a partir de los agregados. Pura y estática a propósito: es la
     * garantía de retrocompatibilidad del cierre de curso y lo que más conviene testear.
     */
    public static function decidirResultado(
        string $criterio,
        int $cargadas,
        int $aprobadas,
        int $desaprobadas,
        int $indeterminadas,
        ?float $promedio,
        ?float $notaAprobacion
    ): string {
        // Sin nada cargado no se puede afirmar nada: nunca desaprueba.
        if ($cargadas === 0) {
            return self::RESULTADO_SIN_DATOS;
        }

        if ($criterio === InstitutoConfiguracion::CRITERIO_TODAS) {
            if ($desaprobadas > 0) {
                return self::RESULTADO_DESAPROBADO;
            }

            // Si alguna no se pudo evaluar, no se afirma que aprobó todo.
            return $indeterminadas > 0 ? self::RESULTADO_SIN_DATOS : self::RESULTADO_APROBADO;
        }

        // Criterio por promedio.
        if ($promedio === null || $notaAprobacion === null) {
            // Sin promedio numérico posible (escala conceptual sin equivalencias) se cae
            // a "todas": es lo único que se puede afirmar con la información disponible.
            if ($desaprobadas > 0) {
                return self::RESULTADO_DESAPROBADO;
            }

            return $indeterminadas > 0 ? self::RESULTADO_SIN_DATOS : self::RESULTADO_APROBADO;
        }

        return $promedio >= $notaAprobacion ? self::RESULTADO_APROBADO : self::RESULTADO_DESAPROBADO;
    }

    /**
     * Nota de aprobación aplicable: la de la última evaluación que cuenta.
     *
     * @param Calificacion[] $calificaciones
     */
    private function aprobacionDeLaEscala(array $calificaciones): ?float
    {
        foreach ($calificaciones as $calificacion) {
            $evaluacion = $calificacion->getEvaluacion();
            if ($evaluacion && $evaluacion->isCuentaParaPromedio() && $evaluacion->getNotaAprobacion() !== null) {
                return $evaluacion->getNotaAprobacion();
            }
        }

        return null;
    }
}
