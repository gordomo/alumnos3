<?php

namespace App\Controller;

use App\Entity\AsistenciaAlumnos;
use App\Entity\Alumno;
use App\Entity\Curso;
use App\Repository\AlumnoRepository;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\CursoRepository;
use App\Repository\ProfesorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @Route("/asistencias/alumnos")
 */
#[IsGranted('ROLE_PROFESOR')]
class AsistenciaAlumnosController extends AbstractController
{
    /**
     * @Route("/", name="app_asistencia_alumnos_index", methods={"GET", "POST"})
     */
    public function index(
        Request $request,
        AsistenciaAlumnosRepository $asistenciaRepository,
        CursoRepository $cursoRepository,
        ProfesorRepository $profesorRepository,
        EntityManagerInterface $entityManager
    ): Response
    {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException('Debes estar autenticado.');
        }
        
        $instituto = $user->getInstituto();
        
        // Obtener fecha del formulario o usar la fecha actual
        $fecha = $request->get('fecha', date('Y-m-d'));
        $cursoId = $request->get('curso');
        
        // Obtener el profesor asociado al usuario
        $profesor = $profesorRepository->findOneBy(['user' => $this->getUser()]);
        
        if (!$profesor) {
            throw $this->createAccessDeniedException('No se encontró el profesor asociado a su usuario.');
        }
        
        // Obtener los cursos del profesor
        $cursos = $profesor->getCursos();
        
        // Si se seleccionó un curso, obtener las asistencias de ese curso para la fecha seleccionada
        if ($cursoId) {
            $curso = $cursoRepository->find($cursoId);
            
            // Verificar que el curso pertenece al profesor
            if (!$cursos->contains($curso)) {
                throw $this->createAccessDeniedException('No tiene acceso a este curso.');
            }

            // Si es una petición POST, procesar el formulario de asistencia
            if ($request->isMethod('POST')) {
                $asistencias = $request->request->all('asistencias');
                $observaciones = $request->request->all('observaciones');
                $fechaAsistencia = new \DateTime($fecha);

                // Eliminar asistencias existentes para este curso y fecha
                $asistenciasExistentes = $asistenciaRepository->findBy([
                    'curso' => $curso,
                    'fecha' => $fechaAsistencia
                ]);

                foreach ($asistenciasExistentes as $asistenciaExistente) {
                    $entityManager->remove($asistenciaExistente);
                }
                $entityManager->flush();

                // Crear nuevas asistencias (solo para presente o ausente; sin_registro no crea registro)
                foreach ($asistencias as $alumnoId => $valor) {
                    if ($valor === 'sin_registro') {
                        continue;
                    }
                    $alumno = $entityManager->getRepository(Alumno::class)->find($alumnoId);
                    if (!$alumno) {
                        continue;
                    }

                    $asistencia = new AsistenciaAlumnos();
                    $asistencia->setAlumno($alumno);
                    $asistencia->setCurso($curso);
                    $asistencia->setFecha($fechaAsistencia);
                    $asistencia->setPresente($valor === '1');
                    $asistencia->setObservaciones($observaciones[$alumnoId] ?? '');

                    $entityManager->persist($asistencia);
                }

                $entityManager->flush();
                $this->addFlash('success', 'Asistencias guardadas correctamente');
                
                return $this->redirectToRoute('app_asistencia_alumnos_index', [
                    'fecha' => $fecha,
                    'curso' => $cursoId
                ]);
            }
            
            $asistencias = $asistenciaRepository->findByCursoAndDate($curso, new \DateTime($fecha));
            
            // Obtener todos los alumnos del curso
            $alumnos = $curso->getAlumnos();
            
            // Crear un array con todos los alumnos y su estado de asistencia
            $asistenciasPorAlumno = [];
            foreach ($alumnos as $alumno) {
                $asistencia = $asistenciaRepository->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'fecha' => new \DateTime($fecha)
                ]);
                
                $asistenciasPorAlumno[] = [
                    'alumno' => $alumno,
                    'presente' => $asistencia ? $asistencia->getPresente() : null,
                    'observaciones' => $asistencia ? $asistencia->getObservaciones() : ''
                ];
            }
            
            // Validar si la fecha está configurada para el curso
            $fechaValida = $this->validarFechaCurso($curso, $fecha);
            
            return $this->render('asistencia_alumnos/index.html.twig', [
                'asistenciasPorAlumno' => $asistenciasPorAlumno,
                'fecha' => $fecha,
                'cursos' => $cursos,
                'cursoSeleccionado' => $curso,
                'fechaValida' => $fechaValida
            ]);
        }
        
        // Si no se seleccionó un curso, mostrar la lista de cursos
        return $this->render('asistencia_alumnos/index.html.twig', [
            'fecha' => $fecha,
            'cursos' => $cursos,
            'cursoSeleccionado' => null
        ]);
    }

    /**
     *@Route("/curso/{id}", name="app_asistencia_alumnos_curso", methods={"POST"})
     */
    public function tomarAsistencia(
        Request $request,
        CursoRepository $cursoRepository,
        AsistenciaAlumnosRepository $asistenciaRepository,
        EntityManagerInterface $entityManager,
        $id
    ): Response
    {
        $cursoId = $request->request->get('curso', $id);
        $fecha = $request->request->get('fecha');
        $asistencias = $request->request->all('asistencias');
        $observaciones = $request->request->all('observaciones');

        $curso = $cursoRepository->find($cursoId);
        if (!$curso) {
            throw $this->createNotFoundException('Curso no encontrado');
        }

        // Verificar que el profesor tenga acceso a este curso
        $profesor = $this->getUser()->getProfesor();
        if (!$profesor || !$curso->getProfesores()->contains($profesor)) {
            throw $this->createAccessDeniedException('No tienes permiso para tomar asistencia en este curso');
        }

        // Obtener la fecha actual si no se proporciona una
        if (!$fecha) {
            $fecha = new \DateTime();
        } else {
            $fecha = new \DateTime($fecha);
        }

        // Eliminar asistencias existentes para este curso y fecha
        $asistenciasExistentes = $asistenciaRepository->findBy([
            'curso' => $curso,
            'fecha' => $fecha
        ]);

        foreach ($asistenciasExistentes as $asistenciaExistente) {
            $entityManager->remove($asistenciaExistente);
        }
        $entityManager->flush();

        // Crear nuevas asistencias
        foreach ($asistencias as $alumnoId => $presente) {
            $alumno = $entityManager->getRepository(Alumno::class)->find($alumnoId);
            if (!$alumno) {
                continue;
            }

            $asistencia = new AsistenciaAlumnos();
            $asistencia->setAlumno($alumno);
            $asistencia->setCurso($curso);
            $asistencia->setFecha($fecha);
            $asistencia->setPresente($presente === '1');
            $asistencia->setObservaciones($observaciones[$alumnoId] ?? '');

            $entityManager->persist($asistencia);
        }

        $entityManager->flush();

        $this->addFlash('success', 'Asistencias guardadas correctamente');
        return $this->redirectToRoute('app_asistencia_alumnos_index');
    }

    /**
     * Valida si una fecha está configurada para un curso
     * Verifica que la fecha esté dentro del rango del curso y que coincida con los días configurados
     * 
     * @param \App\Entity\Curso $curso
     * @param string $fecha Fecha en formato Y-m-d
     * @return array ['valida' => bool, 'mensaje' => string]
     */
    private function validarFechaCurso($curso, string $fecha): array
    {
        $fechaObj = new \DateTime($fecha);
        
        // Verificar si el curso tiene configuración de fechas
        if (!$curso->getFechaInicio() || !$curso->getFechaFin()) {
            return [
                'valida' => false,
                'mensaje' => 'El curso no tiene fechas de inicio y fin configuradas.'
            ];
        }
        
        // Verificar si la fecha está dentro del rango del curso
        if ($fechaObj < $curso->getFechaInicio() || $fechaObj > $curso->getFechaFin()) {
            return [
                'valida' => false,
                'mensaje' => sprintf(
                    'La fecha seleccionada está fuera del rango del curso (del %s al %s).',
                    $curso->getFechaInicio()->format('d/m/Y'),
                    $curso->getFechaFin()->format('d/m/Y')
                )
            ];
        }
        
        // Verificar si el curso tiene días configurados
        $diasCurso = $curso->getDias();
        if (empty($diasCurso)) {
            return [
                'valida' => false,
                'mensaje' => 'El curso no tiene días de la semana configurados.'
            ];
        }
        
        // Mapeo de días de la semana en español
        $diasSemana = [
            'Domingo' => 0,
            'Lunes' => 1,
            'Martes' => 2,
            'Miercoles' => 3,
            'Jueves' => 4,
            'Viernes' => 5,
            'Sabado' => 6
        ];
        
        // Obtener el día de la semana de la fecha (0 = Domingo, 1 = Lunes, etc.)
        $diaSemanaFecha = (int)$fechaObj->format('w');
        
        // Convertir los días del curso a números
        $diasCursoNumeros = array_map(function($dia) use ($diasSemana) {
            return $diasSemana[$dia] ?? null;
        }, $diasCurso);
        
        // Verificar si el día de la semana de la fecha está en los días configurados
        if (!in_array($diaSemanaFecha, $diasCursoNumeros)) {
            $diasTexto = implode(', ', $diasCurso);
            return [
                'valida' => false,
                'mensaje' => sprintf(
                    'La fecha seleccionada (%s) no coincide con los días configurados para este curso (%s).',
                    $this->obtenerNombreDia($diaSemanaFecha),
                    $diasTexto
                )
            ];
        }
        
        return [
            'valida' => true,
            'mensaje' => ''
        ];
    }

    /**
     * Obtiene el nombre del día de la semana en español
     * 
     * @param int $diaSemana 0 = Domingo, 1 = Lunes, etc.
     * @return string
     */
    private function obtenerNombreDia(int $diaSemana): string
    {
        $dias = [
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado'
        ];
        
        return $dias[$diaSemana] ?? 'Desconocido';
    }
} 