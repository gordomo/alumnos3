<?php

namespace App\Controller;

use App\Entity\AsistenciaAlumnos;
use App\Entity\Alumno;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\CursoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @Route("/instituto/asistencias")
 */
class AsistenciaInstitutoController extends AbstractController
{
    /**
     * @Route("/", name="app_instituto_asistencias_index", methods={"GET", "POST"})
     */
    public function index(
        Request $request,
        AsistenciaAlumnosRepository $asistenciaRepository,
        CursoRepository $cursoRepository,
        EntityManagerInterface $entityManager
    ): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Obtener fecha del formulario o usar la fecha actual
        $fecha = $request->get('fecha', date('Y-m-d'));
        $cursoId = $request->get('curso');
        $modo = $request->get('modo', 'edicion'); // 'edicion' o 'lectura'
        
        // Obtener todos los cursos del instituto
        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);
        
        // Si se seleccionó un curso, obtener las asistencias de ese curso para la fecha seleccionada
        if ($cursoId) {
            $curso = $cursoRepository->find($cursoId);
            
            // Verificar que el curso pertenece al instituto del usuario
            if ($curso->getInstituto() !== $instituto) {
                throw $this->createAccessDeniedException('No tiene acceso a este curso.');
            }

            // Si es una petición POST, procesar el formulario de asistencia
            if ($request->isMethod('POST') && $modo === 'edicion') {
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

                // Crear nuevas asistencias
                foreach ($asistencias as $alumnoId => $presente) {
                    $alumno = $entityManager->getRepository(Alumno::class)->find($alumnoId);
                    if (!$alumno) {
                        continue;
                    }

                    $asistencia = new AsistenciaAlumnos();
                    $asistencia->setAlumno($alumno);
                    $asistencia->setCurso($curso);
                    $asistencia->setFecha($fechaAsistencia);
                    $asistencia->setPresente($presente === '1');
                    $asistencia->setObservaciones($observaciones[$alumnoId] ?? '');

                    $entityManager->persist($asistencia);
                }

                $entityManager->flush();
                $this->addFlash('success', 'Asistencias guardadas correctamente');
                
                return $this->redirectToRoute('app_instituto_asistencias_index', [
                    'fecha' => $fecha,
                    'curso' => $cursoId,
                    'modo' => $modo
                ]);
            }
            
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
                    'presente' => $asistencia ? $asistencia->getPresente() : false,
                    'observaciones' => $asistencia ? $asistencia->getObservaciones() : ''
                ];
            }
            
            return $this->render('asistencia_instituto/index.html.twig', [
                'asistenciasPorAlumno' => $asistenciasPorAlumno,
                'fecha' => $fecha,
                'cursos' => $cursos,
                'cursoSeleccionado' => $curso,
                'modo' => $modo
            ]);
        }
        
        // Si no se seleccionó un curso, mostrar la lista de cursos
        return $this->render('asistencia_instituto/index.html.twig', [
            'fecha' => $fecha,
            'cursos' => $cursos,
            'cursoSeleccionado' => null,
            'modo' => $modo
        ]);
    }

    /**
     * @Route("/informe", name="app_instituto_asistencias_informe", methods={"GET"})
     */
    public function informe(
        Request $request,
        AsistenciaAlumnosRepository $asistenciaRepository,
        CursoRepository $cursoRepository
    ): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Obtener parámetros de búsqueda
        $cursoId = $request->get('curso');
        $tipoInforme = $request->get('tipo', 'dia'); // dia, semana, mes
        $fecha = $request->get('fecha', date('Y-m-d'));
        
        // Obtener todos los cursos del instituto
        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);
        
        // Inicializar variables
        $curso = null;
        $informeAsistencias = [];
        $fechaInicio = null;
        $fechaFin = null;
        
        if ($cursoId) {
            $curso = $cursoRepository->find($cursoId);
            
            // Verificar que el curso pertenece al instituto del usuario
            if ($curso->getInstituto() !== $instituto) {
                throw $this->createAccessDeniedException('No tiene acceso a este curso.');
            }
            
            // Calcular fechas según tipo de informe
            $fechaObj = new \DateTime($fecha);
            
            switch ($tipoInforme) {
                case 'semana':
                    // Obtener el primer día de la semana (lunes)
                    $diaSemana = $fechaObj->format('N');
                    $diasAtras = $diaSemana - 1;
                    $fechaInicio = clone $fechaObj;
                    $fechaInicio->modify("-$diasAtras days");
                    
                    // Obtener el último día de la semana (domingo)
                    $fechaFin = clone $fechaInicio;
                    $fechaFin->modify('+6 days');
                    break;
                    
                case 'mes':
                    // Primer día del mes
                    $fechaInicio = new \DateTime($fechaObj->format('Y-m-01'));
                    
                    // Último día del mes
                    $fechaFin = new \DateTime($fechaObj->format('Y-m-t'));
                    break;
                    
                default: // 'dia'
                    $fechaInicio = clone $fechaObj;
                    $fechaFin = clone $fechaObj;
            }
            
            // Obtener asistencias para el rango de fechas
            $asistencias = $asistenciaRepository->findByDateRange(
                $curso,
                $fechaInicio,
                $fechaFin
            );
            
            // Obtener todos los alumnos del curso
            $alumnos = $curso->getAlumnos();
            
            // Preparar estructura para mostrar el informe según el tipo
            if ($tipoInforme === 'dia') {
                // Para informe diario, similar a la vista normal
                foreach ($alumnos as $alumno) {
                    $asistencia = $asistenciaRepository->findOneBy([
                        'alumno' => $alumno,
                        'curso' => $curso,
                        'fecha' => $fechaObj
                    ]);
                    
                    $informeAsistencias[] = [
                        'alumno' => $alumno,
                        'fecha' => $fechaObj->format('Y-m-d'),
                        'presente' => $asistencia ? $asistencia->getPresente() : null,
                        'observaciones' => $asistencia ? $asistencia->getObservaciones() : ''
                    ];
                }
            } else {
                // Para informes semanales y mensuales, mostrar todas las fechas
                // Crear un array por alumno con todas las fechas
                $asistenciasPorAlumno = [];
                $fechasArray = [];
                
                // Generar todas las fechas en el rango
                $fechaIterator = clone $fechaInicio;
                while ($fechaIterator <= $fechaFin) {
                    $fechasArray[] = $fechaIterator->format('Y-m-d');
                    $fechaIterator->modify('+1 day');
                }
                
                // Inicializar la estructura para todos los alumnos
                foreach ($alumnos as $alumno) {
                    $asistenciasPorAlumno[$alumno->getId()] = [
                        'alumno' => $alumno,
                        'fechas' => []
                    ];
                    
                    foreach ($fechasArray as $fechaStr) {
                        $asistenciasPorAlumno[$alumno->getId()]['fechas'][$fechaStr] = [
                            'presente' => null,
                            'observaciones' => ''
                        ];
                    }
                }
                
                // Rellenar con los datos reales de asistencia
                foreach ($asistencias as $asistencia) {
                    $alumnoId = $asistencia->getAlumno()->getId();
                    $fechaStr = $asistencia->getFecha()->format('Y-m-d');
                    
                    if (isset($asistenciasPorAlumno[$alumnoId]) && isset($asistenciasPorAlumno[$alumnoId]['fechas'][$fechaStr])) {
                        $asistenciasPorAlumno[$alumnoId]['fechas'][$fechaStr] = [
                            'presente' => $asistencia->getPresente(),
                            'observaciones' => $asistencia->getObservaciones()
                        ];
                    }
                }
                
                $informeAsistencias = [
                    'fechas' => $fechasArray,
                    'alumnos' => array_values($asistenciasPorAlumno)
                ];
            }
        }
        
        return $this->render('asistencia_instituto/informe.html.twig', [
            'cursos' => $cursos,
            'curso' => $curso,
            'tipoInforme' => $tipoInforme,
            'fecha' => $fecha,
            'fechaInicio' => $fechaInicio ? $fechaInicio->format('Y-m-d') : null,
            'fechaFin' => $fechaFin ? $fechaFin->format('Y-m-d') : null,
            'informeAsistencias' => $informeAsistencias
        ]);
    }

    /**
     * @Route("/exportar/{formato}", name="app_instituto_asistencias_exportar", methods={"GET"})
     */
    public function exportar(
        Request $request,
        AsistenciaAlumnosRepository $asistenciaRepository,
        CursoRepository $cursoRepository,
        string $formato = 'pdf'
    ): Response
    {
        // Obtener los mismos parámetros que en el informe
        $cursoId = $request->get('curso');
        $tipoInforme = $request->get('tipo', 'dia');
        $fecha = $request->get('fecha', date('Y-m-d'));
        
        // Validar formato
        if (!in_array($formato, ['pdf', 'excel', 'csv'])) {
            throw $this->createNotFoundException('Formato no soportado');
        }
        
        // Lógica similar a la del método informe para obtener los datos
        // Aquí implementaríamos la exportación según el formato...
        
        // Por ahora, retornamos un mensaje indicando que se implementará
        return $this->render('asistencia_instituto/exportar.html.twig', [
            'formato' => $formato,
            'mensaje' => 'La función de exportación estará disponible próximamente.'
        ]);
    }
} 