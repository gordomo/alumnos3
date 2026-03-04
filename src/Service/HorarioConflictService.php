<?php

namespace App\Service;

use App\Entity\Curso;
use App\Entity\Profesor;
use App\Repository\CursoRepository;

class HorarioConflictService
{
    private CursoRepository $cursoRepository;

    public function __construct(CursoRepository $cursoRepository)
    {
        $this->cursoRepository = $cursoRepository;
    }
    
    /**
     * Método público para obtener cursos del profesor (para depuración)
     */
    public function getCursosProfesor(Profesor $profesor): array
    {
        return $this->cursoRepository->findByProfesor($profesor);
    }

    /**
     * Obtiene los slots (dia, inicio, fin) de un curso, ya sea desde la colección horarios o desde campos legacy.
     *
     * @return list<array{dia: string, inicio: \DateTimeInterface, fin: \DateTimeInterface}>
     */
    private function getSlots(Curso $curso): array
    {
        $slots = [];
        if ($curso->getHorarios()->count() > 0) {
            foreach ($curso->getHorarios() as $h) {
                if (!$h->getDia() || !$h->getHorarioInicio() || !$h->getHorarioFin()) {
                    continue;
                }
                $inicio = $h->getHorarioInicio() instanceof \DateTimeInterface ? $h->getHorarioInicio() : new \DateTime($h->getHorarioInicio()->format('H:i'));
                $fin = $h->getHorarioFin() instanceof \DateTimeInterface ? $h->getHorarioFin() : new \DateTime($h->getHorarioFin()->format('H:i'));
                $slots[] = ['dia' => $h->getDia(), 'inicio' => $inicio, 'fin' => $fin];
            }
            return $slots;
        }
        $dias = $curso->getDias();
        if (!is_array($dias) || empty($dias) || !$curso->getHorarioInicio() || !$curso->getHorarioFin()) {
            return [];
        }
        $inicio = $curso->getHorarioInicio() instanceof \DateTimeInterface ? $curso->getHorarioInicio() : new \DateTime($curso->getHorarioInicio()->format('H:i'));
        $fin = $curso->getHorarioFin() instanceof \DateTimeInterface ? $curso->getHorarioFin() : new \DateTime($curso->getHorarioFin()->format('H:i'));
        foreach ($dias as $dia) {
            $slots[] = ['dia' => $dia, 'inicio' => $inicio, 'fin' => $fin];
        }
        return $slots;
    }

    private function minutosDesdeMedianoche(\DateTimeInterface $t): int
    {
        return (int) $t->format('H') * 60 + (int) $t->format('i');
    }

    /**
     * Detecta conflictos de horarios entre un curso y los cursos existentes de un profesor.
     * Soporta cursos con múltiples horarios por día (colección horarios).
     *
     * @param Curso $curso El curso a verificar
     * @param Profesor $profesor El profesor a verificar
     * @param int|null $excludeCursoId ID del curso a excluir de la verificación (útil en edición)
     * @return array Array con información de conflictos encontrados
     */
    public function detectarConflictos(Curso $curso, Profesor $profesor, ?int $excludeCursoId = null): array
    {
        $conflictos = [];
        $slotsNuevo = $this->getSlots($curso);
        if (empty($slotsNuevo)) {
            return $conflictos;
        }

        $cursosProfesor = $this->cursoRepository->findByProfesor($profesor);

        foreach ($cursosProfesor as $cursoExistente) {
            if ($excludeCursoId !== null && $cursoExistente->getId() === $excludeCursoId) {
                continue;
            }
            if ($cursoExistente->getId() === $curso->getId()) {
                continue;
            }

            $slotsExistente = $this->getSlots($cursoExistente);
            foreach ($slotsNuevo as $slotNuevo) {
                foreach ($slotsExistente as $slotExistente) {
                    if ($slotNuevo['dia'] !== $slotExistente['dia']) {
                        continue;
                    }
                    $inicio1 = $this->minutosDesdeMedianoche($slotNuevo['inicio']);
                    $fin1 = $this->minutosDesdeMedianoche($slotNuevo['fin']);
                    $inicio2 = $this->minutosDesdeMedianoche($slotExistente['inicio']);
                    $fin2 = $this->minutosDesdeMedianoche($slotExistente['fin']);
                    if ($inicio1 < $fin2 && $fin1 > $inicio2) {
                        $conflictos[] = [
                            'curso' => $cursoExistente,
                            'dias' => [$slotNuevo['dia']],
                            'horarioExistente' => $slotExistente['inicio']->format('H:i') . ' - ' . $slotExistente['fin']->format('H:i'),
                            'horarioNuevo' => $slotNuevo['inicio']->format('H:i') . ' - ' . $slotNuevo['fin']->format('H:i'),
                        ];
                        break 2; // un conflicto por curso existente es suficiente
                    }
                }
            }
        }

        return $conflictos;
    }
    
    /**
     * Genera un mensaje legible de conflicto
     * 
     * @param array $conflictos Array de conflictos devuelto por detectarConflictos()
     * @return string Mensaje formateado
     */
    public function generarMensajeConflicto(array $conflictos): string
    {
        if (empty($conflictos)) {
            return '';
        }
        
        $mensajes = [];
        foreach ($conflictos as $conflicto) {
            $diasTexto = implode(', ', $conflicto['dias']);
            $mensajes[] = sprintf(
                'El curso "%s" se dicta los días %s de %s, solapándose con el horario propuesto (%s)',
                $conflicto['curso']->getNombre(),
                $diasTexto,
                $conflicto['horarioExistente'],
                $conflicto['horarioNuevo']
            );
        }
        
        return implode('. ', $mensajes) . '.';
    }
}
