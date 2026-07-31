<?php

namespace App\Service;

use App\Entity\Calificacion;
use App\Entity\ConceptoCalificacion;
use App\Entity\Curso;
use App\Entity\Evaluacion;
use App\Entity\User;
use App\Repository\AlumnoCursoHistoricoRepository;
use App\Repository\CalificacionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Armado y guardado de la grilla de calificaciones de una evaluación.
 *
 * La carga en lote imita el upsert con lista blanca de AsistenciaInstitutoController
 * (y no el borrar-y-recrear del controller de profesores): solo se tocan los alumnos que
 * vinieron en el POST, así que dos operadores editando distintos alumnos no se pisan.
 */
class CalificacionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalificacionRepository $calificacionRepository,
        private AlumnoCursoHistoricoRepository $historicoRepository,
        private EscalaCalificacionService $escalaService
    ) {
    }

    /**
     * Inscripciones de un curso, ordenadas por apellido y nombre. Base de toda grilla.
     *
     * @return array<int, array{historico: mixed, alumno: mixed, cargable: bool, motivoNoCargable: string|null}>
     */
    public function getInscripciones(Curso $curso): array
    {
        $filas = [];
        foreach ($this->historicoRepository->findByCurso($curso) as $historico) {
            // Las bajas administrativas no se califican.
            $cargable = $historico->getMotivoBaja() !== 'baja_administrativa';

            $filas[] = [
                'historico' => $historico,
                'alumno' => $historico->getAlumno(),
                'cargable' => $cargable,
                'motivoNoCargable' => $cargable ? null : 'Baja administrativa',
            ];
        }

        usort($filas, function ($a, $b) {
            $apellido = strcmp((string) $a['alumno']->getApellido(), (string) $b['alumno']->getApellido());

            return $apellido !== 0 ? $apellido : strcmp((string) $a['alumno']->getNombre(), (string) $b['alumno']->getNombre());
        });

        return $filas;
    }

    /**
     * Filas de la grilla de una evaluación: las inscripciones más la nota de cada una.
     *
     * @return array<int, array{historico: mixed, alumno: mixed, calificacion: Calificacion|null, cargable: bool, motivoNoCargable: string|null}>
     */
    public function getGrilla(Evaluacion $evaluacion): array
    {
        $calificaciones = $evaluacion->getId()
            ? $this->calificacionRepository->findByEvaluacionIndexadoPorHistorico($evaluacion)
            : [];

        $filas = [];
        foreach ($this->getInscripciones($evaluacion->getCurso()) as $fila) {
            $fila['calificacion'] = $calificaciones[$fila['historico']->getId()] ?? null;
            $filas[] = $fila;
        }

        return $filas;
    }

    /**
     * Guarda la grilla.
     *
     * @param array<int, string> $notas             historicoId => valor numérico
     * @param array<int, string> $conceptos         historicoId => id de ConceptoCalificacion
     * @param array<int, string> $observaciones     historicoId => texto
     * @param array<int, string> $ausentes          historicoId => '1'
     * @param int[]              $historicosEnviados lista blanca de ids
     *
     * @return array{guardadas: int, actualizadas: int, eliminadas: int, errores: string[]}
     */
    public function guardarGrilla(
        Evaluacion $evaluacion,
        array $notas,
        array $conceptos,
        array $observaciones,
        array $ausentes,
        array $historicosEnviados,
        ?User $usuario
    ): array {
        $instituto = $evaluacion->getInstituto();
        $escala = $this->escalaService->getEscalaParaEvaluacion($evaluacion);
        $existentes = $this->calificacionRepository->findByEvaluacionIndexadoPorHistorico($evaluacion);

        $enviados = array_map('intval', $historicosEnviados);
        $guardadas = 0;
        $actualizadas = 0;
        $eliminadas = 0;
        $errores = [];

        foreach ($this->historicoRepository->findByCurso($evaluacion->getCurso()) as $historico) {
            $historicoId = $historico->getId();

            // Lista blanca: lo que no vino en el POST no se toca.
            if (!in_array($historicoId, $enviados, true)) {
                continue;
            }

            if ($historico->getMotivoBaja() === 'baja_administrativa') {
                continue;
            }

            $nombreAlumno = trim($historico->getAlumno()->getApellido() . ', ' . $historico->getAlumno()->getNombre());

            $ausente = ($ausentes[$historicoId] ?? '') === '1';
            $valorCrudo = trim((string) ($notas[$historicoId] ?? ''));
            $conceptoId = trim((string) ($conceptos[$historicoId] ?? ''));
            $obs = trim((string) ($observaciones[$historicoId] ?? ''));

            $valorNumerico = null;
            if (!$ausente && $valorCrudo !== '') {
                if (!is_numeric(str_replace(',', '.', $valorCrudo))) {
                    $errores[] = sprintf('%s: "%s" no es un número válido.', $nombreAlumno, $valorCrudo);
                    continue;
                }
                $valorNumerico = (float) str_replace(',', '.', $valorCrudo);
            }

            $concepto = null;
            if (!$ausente && $conceptoId !== '') {
                $concepto = $this->entityManager->getRepository(ConceptoCalificacion::class)->find((int) $conceptoId);
                // Revalidación server-side: el select es editable desde el navegador.
                if (!$concepto || !$this->escalaService->conceptoPerteneceAInstituto($concepto, $instituto)) {
                    $errores[] = sprintf('%s: el concepto seleccionado no es válido.', $nombreAlumno);
                    continue;
                }
            }

            $erroresValidacion = $this->escalaService->validar($escala, $valorNumerico, $concepto, $ausente);
            if ($erroresValidacion) {
                foreach ($erroresValidacion as $error) {
                    $errores[] = $nombreAlumno . ': ' . $error;
                }
                continue;
            }

            $calificacion = $existentes[$historicoId] ?? null;
            $vacia = !$ausente && $valorNumerico === null && $concepto === null && $obs === '';

            // Todo vacío equivale a "sin nota": se borra la fila, igual que sin_registro
            // en asistencias.
            if ($vacia) {
                if ($calificacion) {
                    $this->entityManager->remove($calificacion);
                    $eliminadas++;
                }
                continue;
            }

            if (!$calificacion) {
                $calificacion = new Calificacion();
                $calificacion->setEvaluacion($evaluacion);
                $calificacion->setCursoHistorico($historico);
                $calificacion->setInstituto($instituto);
                $this->entityManager->persist($calificacion);
                $guardadas++;
            } else {
                $calificacion->setUpdatedAt(new \DateTime());
                $actualizadas++;
            }

            $calificacion->setAusente($ausente);
            $calificacion->setValorNumerico($ausente ? null : $valorNumerico);
            $calificacion->setConcepto($ausente ? null : $concepto);
            $calificacion->setObservaciones($obs !== '' ? $obs : null);
            $calificacion->setCargadoPor($usuario);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Doble submit sobre el mismo alumno. El índice único evita el duplicado; se
            // informa para que el operador recargue y vea el estado real.
            return [
                'guardadas' => 0,
                'actualizadas' => 0,
                'eliminadas' => 0,
                'errores' => ['Otro usuario guardó esta evaluación al mismo tiempo. Recargá la página para ver las notas actuales.'],
            ];
        }

        return [
            'guardadas' => $guardadas,
            'actualizadas' => $actualizadas,
            'eliminadas' => $eliminadas,
            'errores' => $errores,
        ];
    }
}
