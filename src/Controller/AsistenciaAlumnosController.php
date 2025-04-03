<?php

namespace App\Controller;

use App\Entity\AsistenciaAlumnos;
use App\Entity\Curso;
use App\Repository\AlumnoRepository;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\CursoRepository;
use App\Repository\ProfesorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/asistencias/alumnos")
 */
class AsistenciaAlumnosController extends AbstractController
{
    /**
     * @Route("/", name="app_asistencia_alumnos_index", methods={"GET"})
     */
    public function index(Request $request, AsistenciaAlumnosRepository $asistenciaRepository, CursoRepository $cursoRepository, ProfesorRepository $profesorRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
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
                    'presente' => $asistencia ? $asistencia->isPresente() : false,
                    'observaciones' => $asistencia ? $asistencia->getObservaciones() : ''
                ];
            }
            
            return $this->render('asistencia_alumnos/index.html.twig', [
                'asistenciasPorAlumno' => $asistenciasPorAlumno,
                'fecha' => $fecha,
                'cursos' => $cursos,
                'cursoSeleccionado' => $curso
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
     * @Route("/curso/{id}", name="app_asistencia_alumnos_curso", methods={"GET", "POST"})
     */
    public function tomarAsistencia(Request $request, Curso $curso, AlumnoRepository $alumnoRepository, AsistenciaAlumnosRepository $asistenciaRepository, ProfesorRepository $profesorRepository): Response
    {
        // Verificar que el profesor tiene acceso al curso
        $profesor = $profesorRepository->findOneBy(['user' => $this->getUser()]);
        if (!$profesor || !$profesor->getCursos()->contains($curso)) {
            throw $this->createAccessDeniedException('No tiene acceso a este curso.');
        }
        
        $fecha = new \DateTime($request->get('fecha', 'today'));
        $alumnos = $curso->getAlumnos();
        
        // Verificar si ya se tomaron asistencias para este curso en esta fecha
        $asistenciasExistentes = $asistenciaRepository->findByCursoAndDate($curso, $fecha);
        
        if ($request->isMethod('POST')) {
            $asistencias = $request->request->get('asistencias', []);
            $observaciones = $request->request->all();
            
            // Eliminar asistencias existentes para esta fecha y curso
            foreach ($asistenciasExistentes as $asistenciaExistente) {
                $asistenciaRepository->remove($asistenciaExistente, false);
            }
            
            // Crear nuevas asistencias
            foreach ($alumnos as $alumno) {
                $asistencia = new AsistenciaAlumnos();
                $asistencia->setAlumno($alumno);
                $asistencia->setCurso($curso);
                $asistencia->setFecha($fecha);
                $asistencia->setPresente(isset($asistencias[$alumno->getId()]));
                $asistencia->setObservaciones($observaciones['observaciones_' . $alumno->getId()] ?? '');
                
                $asistenciaRepository->add($asistencia, false);
            }
            
            $asistenciaRepository->getEntityManager()->flush();
            
            $this->addFlash('success', 'Asistencias guardadas correctamente');
            return $this->redirectToRoute('app_asistencia_alumnos_index', [
                'fecha' => $fecha->format('Y-m-d'),
                'curso' => $curso->getId()
            ]);
        }
        
        return $this->render('asistencia_alumnos/tomar.html.twig', [
            'curso' => $curso,
            'alumnos' => $alumnos,
            'fecha' => $fecha,
            'asistenciasExistentes' => $asistenciasExistentes
        ]);
    }
} 