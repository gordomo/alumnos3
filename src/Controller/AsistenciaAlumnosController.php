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
use App\Service\InstitutoTimezoneService;

/**
 * @Route("/asistencias/alumnos")
 */
#[IsGranted('ROLE_PROFESOR')]
class AsistenciaAlumnosController extends AbstractController
{
    public function __construct(
        private InstitutoTimezoneService $institutoTimezoneService
    ) {
    }
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
        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
        $nowInstituto = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $fechaHoyStr = $nowInstituto->format($dateFormat);

        // Obtener fecha del formulario (puede venir en formato del instituto o Y-m-d) o usar hoy
        $fechaRequest = $request->get('fecha', $fechaHoyStr);
        $fechaObj = $this->institutoTimezoneService->parseDateString($fechaRequest, $dateFormat);
        if (!$fechaObj) {
            $fechaObj = $nowInstituto;
        }
        $fecha = $fechaObj->format($dateFormat);
        $fechaYmd = $fechaObj->format('Y-m-d');
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
                // El id del token es fijo: el autoguardado reenvia el mismo formulario.
                if (!$this->isCsrfTokenValid('asistencias', (string) $request->request->get('_token'))) {
                    $this->addFlash('danger', 'Token de seguridad invalido. Recarga la pagina y volve a intentar.');
                    return $this->redirectToRoute('app_asistencia_alumnos_index', ['fecha' => $fecha, 'curso' => $cursoId]);
                }

                $asistencias = $request->request->all('asistencias');
                $observaciones = $request->request->all('observaciones');
                $fechaAsistencia = $fechaObj;

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
            
            $asistencias = $asistenciaRepository->findByCursoAndDate($curso, $fechaObj);
            
            // Obtener todos los alumnos del curso
            $alumnos = $curso->getAlumnos();
            
            // Crear un array con todos los alumnos y su estado de asistencia
            $asistenciasPorAlumno = [];
            foreach ($alumnos as $alumno) {
                $asistencia = $asistenciaRepository->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'fecha' => $fechaObj
                ]);
                
                $asistenciasPorAlumno[] = [
                    'alumno' => $alumno,
                    'presente' => $asistencia ? $asistencia->getPresente() : null,
                    'observaciones' => $asistencia ? $asistencia->getObservaciones() : ''
                ];
            }
            
            // Validar si la fecha está configurada para el curso
            $fechaValida = $this->validarFechaCurso($curso, $fechaYmd);
            
            return $this->render('asistencia_alumnos/index.html.twig', [
                'asistenciasPorAlumno' => $asistenciasPorAlumno,
                'fecha' => $fecha,
                'fechaHoy' => $fechaHoyStr,
                'date_format' => $dateFormat,
                'cursos' => $cursos,
                'cursoSeleccionado' => $curso,
                'fechaValida' => $fechaValida
            ]);
        }

        // Si no se seleccionó un curso, mostrar la lista de cursos
        return $this->render('asistencia_alumnos/index.html.twig', [
            'fecha' => $fecha,
            'fechaHoy' => $fechaHoyStr,
            'date_format' => $dateFormat,
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
        if (!$this->isCsrfTokenValid('asistencia_curso' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad invalido.');
            return $this->redirectToRoute('app_asistencia_alumnos_index');
        }

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
        $instituto = $curso->getInstituto();
        if (!$fecha) {
            $fecha = $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
        } else {
            $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
            $fecha = $this->institutoTimezoneService->parseDateString($fecha, $dateFormat);
            if (!$fecha) {
                $fecha = $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
            }
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
        $instituto = $curso->getInstituto();
        $timezone = new \DateTimeZone($this->institutoTimezoneService->getTimezoneForInstituto($instituto));
        $fechaObj = new \DateTime($fecha, $timezone);
        
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
                    $curso->getFechaInicio()->format($this->institutoTimezoneService->getDateFormatForInstituto($curso->getInstituto())),
                    $curso->getFechaFin()->format($this->institutoTimezoneService->getDateFormatForInstituto($curso->getInstituto()))
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
            // Ordenar los días según el orden de la semana (Lunes primero)
            $ordenDias = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
            $diasCursoOrdenados = array_filter($ordenDias, function($dia) use ($diasCurso) {
                return in_array($dia, $diasCurso);
            });
            
            $diasTexto = implode(', ', $diasCursoOrdenados);
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