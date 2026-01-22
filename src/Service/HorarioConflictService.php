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
     * Detecta conflictos de horarios entre un curso y los cursos existentes de un profesor
     * 
     * @param Curso $curso El curso a verificar
     * @param Profesor $profesor El profesor a verificar
     * @param int|null $excludeCursoId ID del curso a excluir de la verificación (útil en edición)
     * @return array Array con información de conflictos encontrados
     */
    public function detectarConflictos(Curso $curso, Profesor $profesor, ?int $excludeCursoId = null): array
    {
        $conflictos = [];
        
        // Validar que el curso tenga los datos necesarios
        if (!$curso->getHorarioInicio() || !$curso->getHorarioFin() || empty($curso->getDias())) {
            return $conflictos; // Sin conflictos si el curso no tiene horario completo
        }
        
        $diasCurso = $curso->getDias();
        $horarioInicioCurso = $curso->getHorarioInicio();
        $horarioFinCurso = $curso->getHorarioFin();
        
        // Convertir horarios a objetos DateTime para comparación
        if (!$horarioInicioCurso instanceof \DateTimeInterface) {
            $horarioInicioCurso = new \DateTime($horarioInicioCurso);
        }
        if (!$horarioFinCurso instanceof \DateTimeInterface) {
            $horarioFinCurso = new \DateTime($horarioFinCurso);
        }
        
        // Obtener todos los cursos del profesor desde la BD (para asegurar que tenemos todos los cursos existentes)
        // Esto es importante porque cuando se crea un curso nuevo, la relación bidireccional puede no estar completa
        $cursosProfesor = $this->cursoRepository->findByProfesor($profesor);
        
        // Si el curso actual tiene ID y está en la lista, también debemos excluirlo
        // porque findByProfesor puede incluirlo si ya está guardado en BD
        foreach ($cursosProfesor as $cursoExistente) {
            // Excluir el curso actual si se está editando
            if ($excludeCursoId !== null && $cursoExistente->getId() === $excludeCursoId) {
                continue;
            }
            
            // También excluir si es el mismo objeto (aunque no debería pasar con findByProfesor)
            if ($cursoExistente->getId() === $curso->getId()) {
                continue;
            }
            
            // Validar que el curso existente tenga los datos necesarios
            if (!$cursoExistente->getHorarioInicio() || !$cursoExistente->getHorarioFin() || empty($cursoExistente->getDias())) {
                continue;
            }
            
            // Verificar si hay días en común
            $diasCursoExistente = $cursoExistente->getDias();
            
            // Asegurarse de que ambos son arrays
            if (!is_array($diasCurso)) {
                $diasCurso = [];
            }
            if (!is_array($diasCursoExistente)) {
                $diasCursoExistente = [];
            }
            
            $diasComunes = array_intersect($diasCurso, $diasCursoExistente);
            
            if (empty($diasComunes)) {
                continue; // No hay días en común, no hay conflicto
            }
            
            // Convertir horarios del curso existente a DateTime
            $horarioInicioExistente = $cursoExistente->getHorarioInicio();
            $horarioFinExistente = $cursoExistente->getHorarioFin();
            
            if (!$horarioInicioExistente instanceof \DateTimeInterface) {
                $horarioInicioExistente = new \DateTime($horarioInicioExistente);
            }
            if (!$horarioFinExistente instanceof \DateTimeInterface) {
                $horarioFinExistente = new \DateTime($horarioFinExistente);
            }
            
            // Verificar si los horarios se solapan
            // Dos horarios se solapan si: inicio1 < fin2 && fin1 > inicio2
            // Convertir a minutos desde medianoche para comparación precisa
            $inicio1Minutos = (int)$horarioInicioCurso->format('H') * 60 + (int)$horarioInicioCurso->format('i');
            $fin1Minutos = (int)$horarioFinCurso->format('H') * 60 + (int)$horarioFinCurso->format('i');
            $inicio2Minutos = (int)$horarioInicioExistente->format('H') * 60 + (int)$horarioInicioExistente->format('i');
            $fin2Minutos = (int)$horarioFinExistente->format('H') * 60 + (int)$horarioFinExistente->format('i');
            
            // Comparar minutos: dos horarios se solapan si inicio1 < fin2 && fin1 > inicio2
            if ($inicio1Minutos < $fin2Minutos && $fin1Minutos > $inicio2Minutos) {
                // Hay conflicto de horarios
                $conflictos[] = [
                    'curso' => $cursoExistente,
                    'dias' => $diasComunes,
                    'horarioExistente' => $horarioInicioExistente->format('H:i') . ' - ' . $horarioFinExistente->format('H:i'),
                    'horarioNuevo' => $horarioInicioCurso->format('H:i') . ' - ' . $horarioFinCurso->format('H:i'),
                ];
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
