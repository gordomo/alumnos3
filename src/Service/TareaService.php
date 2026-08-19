<?php

namespace App\Service;

use App\Entity\AlumnoCursoHistorico;
use App\Entity\Tarea;
use App\Entity\TareaEntrega;
use App\Entity\User;
use App\Repository\TareaEntregaRepository;
use Doctrine\ORM\EntityManagerInterface;

class TareaService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TareaEntregaRepository $entregaRepository
    ) {
    }

    /**
     * Guarda la grilla de entregas de una tarea.
     *
     * Es un upsert, no un borrar y recrear: volver a guardar corrige lo que ya estaba en lugar
     * de duplicarlo. Y la lista blanca de inscripciones procesadas es lo que evita que alguien
     * marque la entrega de un alumno de otro curso agregando un campo desde el navegador.
     *
     * Una fila sin entrega y sin observación se borra: la ausencia ya significa "no entregada",
     * así que no tiene sentido guardarla.
     *
     * @param AlumnoCursoHistorico[] $inscripciones las del curso, que son la lista blanca
     * @param array<int, bool>       $entregadas    id de inscripción => entregada
     * @param array<int, string>     $observaciones id de inscripción => texto
     * @return array{guardadas: int, borradas: int}
     */
    public function guardarGrilla(
        Tarea $tarea,
        array $inscripciones,
        array $entregadas,
        array $observaciones,
        ?User $usuario = null
    ): array {
        $existentes = $this->entregaRepository->findByTareaIndexadoPorHistorico($tarea);

        $guardadas = 0;
        $borradas = 0;

        foreach ($inscripciones as $inscripcion) {
            $id = $inscripcion->getId();
            $entrega = $existentes[$id] ?? null;

            $marcada = !empty($entregadas[$id]);
            $observacion = isset($observaciones[$id]) ? trim((string) $observaciones[$id]) : '';

            if (!$marcada && $observacion === '') {
                if ($entrega) {
                    $this->entityManager->remove($entrega);
                    $borradas++;
                }
                continue;
            }

            if (!$entrega) {
                $entrega = new TareaEntrega();
                $entrega->setTarea($tarea);
                $entrega->setCursoHistorico($inscripcion);
                $entrega->setInstituto($tarea->getInstituto());
                $this->entityManager->persist($entrega);
            }

            $entrega->setEntregada($marcada);
            $entrega->setObservaciones($observacion !== '' ? $observacion : null);
            $entrega->setCargadoPor($usuario);
            $entrega->setUpdatedAt(new \DateTime());
            $guardadas++;
        }

        $this->entityManager->flush();

        return ['guardadas' => $guardadas, 'borradas' => $borradas];
    }

    /**
     * Resumen de tareas de una inscripción: cuántas se pidieron y cuántas entregó.
     *
     * Pedidas se cuenta sobre las tareas del curso, porque se le piden a todo el curso por
     * igual. Entregadas es de este alumno.
     *
     * @return array{pedidas: int, entregadas: int, porcentaje: float|null}
     */
    public function resumenParaHistorico(AlumnoCursoHistorico $historico): array
    {
        $curso = $historico->getCurso();
        if (!$curso) {
            return ['pedidas' => 0, 'entregadas' => 0, 'porcentaje' => null];
        }

        $pedidas = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Tarea::class, 't')
            ->andWhere('t.curso = :curso')
            ->setParameter('curso', $curso)
            ->getQuery()
            ->getSingleScalarResult();

        $entregadas = 0;
        foreach ($this->entregaRepository->findByHistorico($historico) as $entrega) {
            if ($entrega->isEntregada()) {
                $entregadas++;
            }
        }

        return [
            'pedidas' => $pedidas,
            'entregadas' => $entregadas,
            'porcentaje' => $pedidas > 0 ? round(($entregadas / $pedidas) * 100, 1) : null,
        ];
    }

    /**
     * Pedidas y entregadas de cada período, para la fila de tareas de la libreta.
     *
     * @param \App\Entity\PeriodoAcademico[] $periodos
     * @return array<int, array{pedidas: int, entregadas: int}> indexado por id de período
     */
    public function resumenPorPeriodo(AlumnoCursoHistorico $historico, array $periodos): array
    {
        $curso = $historico->getCurso();
        if (!$curso || !$periodos) {
            return [];
        }

        // Cuántas tareas hay en cada período, contadas sobre el curso.
        $pedidasPorPeriodo = [];
        $filas = $this->entityManager->createQueryBuilder()
            ->select('p.id AS periodo, COUNT(t.id) AS cantidad')
            ->from(Tarea::class, 't')
            ->innerJoin('t.periodo', 'p')
            ->andWhere('t.curso = :curso')
            ->setParameter('curso', $curso)
            ->groupBy('p.id')
            ->getQuery()
            ->getScalarResult();

        foreach ($filas as $fila) {
            $pedidasPorPeriodo[(int) $fila['periodo']] = (int) $fila['cantidad'];
        }

        // Y cuántas entregó este alumno en cada uno.
        $entregadasPorPeriodo = [];
        foreach ($this->entregaRepository->findByHistorico($historico) as $entrega) {
            if (!$entrega->isEntregada()) {
                continue;
            }

            $periodo = $entrega->getTarea() ? $entrega->getTarea()->getPeriodo() : null;
            if (!$periodo) {
                continue;
            }

            $clave = $periodo->getId();
            $entregadasPorPeriodo[$clave] = ($entregadasPorPeriodo[$clave] ?? 0) + 1;
        }

        $resultado = [];
        foreach ($periodos as $periodo) {
            $id = $periodo->getId();
            $resultado[$id] = [
                'pedidas' => $pedidasPorPeriodo[$id] ?? 0,
                'entregadas' => $entregadasPorPeriodo[$id] ?? 0,
            ];
        }

        return $resultado;
    }
}
